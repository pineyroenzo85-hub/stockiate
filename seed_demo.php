<?php
/**
 * stockIAte - seed_demo.php
 * ================================
 * Borra y regenera la base `stockiate_demo` completa, con datos inventados
 * pero verosímiles: 40 productos de perfumería, 90 días de ventas con
 * estacionalidad, lotes con vencimientos, y el historial de correcciones de
 * la IA.
 *
 * PARA QUÉ
 * --------
 * Para poder mostrar el sistema lleno sin depender de que el comercio del
 * piloto haya vendido algo ese día, y sin que una demostración escriba una
 * sola fila en los datos reales. Los datos de demo viven en OTRA BASE, no en
 * una fila marcada con un flag: ver la nota larga en `conexion.php`.
 *
 * ES DETERMINÍSTICO
 * -----------------
 * `mt_srand(SEMILLA)` al arranque: dos corridas seguidas producen exactamente
 * los mismos productos, las mismas ventas y los mismos números. Es lo que
 * permite rehacer una captura de pantalla del informe meses después y que dé
 * igual. Las fechas son relativas a HOY, así que la demo nunca se ve vieja;
 * la única cosa que cambia entre corridas son los hashes de las contraseñas
 * (`password_hash` sale con salt aleatorio a propósito) y los IDs, que no se
 * muestran en ninguna pantalla.
 *
 * CÓMO SE CORRE
 * -------------
 *   php seed_demo.php            (desde la carpeta del proyecto)
 * o el botón "Regenerar datos de demo" del panel de Preferencias, que sólo
 * aparece cuando el `.env` dice `STOCKIATE_MODO=demo`.
 *
 * LA PROTECCIÓN
 * -------------
 * Lo primero que hace es preguntarle al motor `SELECT DATABASE()` y abortar
 * si la respuesta no es exactamente `stockiate_demo`. No alcanza con mirar la
 * constante: la constante es justo lo que alguien podría cambiar sin querer.
 * Además, del `schema.sql` se descartan los `CREATE DATABASE` / `USE`, que
 * apuntan a la base real y son la única forma en que este script podría
 * escribir donde no debe.
 */

const SEMILLA_DEMO = 20260909;

// Cuántos días de historia se generan hacia atrás desde hoy.
const DIAS_HISTORIA = 90;

const NEGOCIO_DEMO_NOMBRE = 'Perfumería Aroma (demo)';
const PASSWORD_DEMO = 'demo1234';

$es_cli = php_sapi_name() === 'cli';

if ($es_cli) {
    // Sin conexión automática: la base de demo puede no existir todavía (es
    // justo lo que este script va a crear), y conexion.php aborta el proceso
    // si no puede conectar. Acá sólo queremos sus constantes y
    // `conectar_mysql()`.
    define('STOCKIATE_SIN_CONEXION_AUTOMATICA', true);
    require_once __DIR__ . '/conexion.php';
} else {
    // Por HTTP hace falta sesión de dueño, igual que cualquier otro endpoint
    // que escribe. Y además hace falta estar en modo demo: en modo normal
    // este endpoint directamente no existe.
    require_once __DIR__ . '/sesion.php';
    cabeceras_json('POST, OPTIONS');
    exigir_metodo('POST');
    exigir_sesion(['dueño']);

    if (!STOCKIATE_MODO_DEMO) {
        responder([
            'ok' => false,
            'mensaje' => 'El generador de datos de demo sólo corre con STOCKIATE_MODO=demo.',
        ], 403);
    }
}

/** Escribe una línea de progreso (a stdout por CLI, al buffer por HTTP). */
$registro = [];
function log_demo(string $linea): void
{
    global $es_cli, $registro;
    $registro[] = $linea;
    if ($es_cli) {
        echo $linea . "\n";
    }
}

/** Corta la corrida con un error, en el formato que corresponda al canal. */
function abortar(string $mensaje): void
{
    global $es_cli;
    if ($es_cli) {
        fwrite(STDERR, "ERROR: $mensaje\n");
        exit(1);
    }
    responder(['ok' => false, 'mensaje' => $mensaje], 409);
}

// ------------------------------------------------------------------
// 1. Guarda: ¿estamos apuntando a la base de demo?
// ------------------------------------------------------------------
if (STOCKIATE_BASE !== STOCKIATE_BASE_DEMO) {
    abortar(
        "La configuración apunta a la base '" . STOCKIATE_BASE . "'. " .
        "Este script sólo escribe en '" . STOCKIATE_BASE_DEMO . "': poné STOCKIATE_MODO=demo en el .env."
    );
}

try {
    $servidor = conectar_mysql(null);
} catch (PDOException $e) {
    abortar('No se pudo conectar a MySQL. ¿Está levantado XAMPP?');
}

// ------------------------------------------------------------------
// 2. Base limpia + esquema
// ------------------------------------------------------------------
// El esquema sale del MISMO schema.sql que la base real, sin copiarlo: si se
// agrega una tabla, la demo la tiene en la corrida siguiente sin que nadie se
// acuerde de actualizar dos archivos.
$ruta_schema = __DIR__ . '/schema.sql';
if (!is_readable($ruta_schema)) {
    abortar('No se encuentra schema.sql al lado de este script.');
}

/**
 * Parte un .sql en sentencias, sacando los comentarios `--`.
 *
 * Los comentarios se cortan por línea y llevando la cuenta de si estamos
 * dentro de una cadena, porque schema.sql tiene comentarios que CONTIENEN
 * comillas (`-- 'dueño' = rol Administrador en la UI`) y también valores con
 * comillas. Un `explode(';')` a secas o un regex sin estado los rompe.
 * No hay strings multilínea ni DELIMITER en este esquema, así que arrancar
 * cada línea fuera de cadena es correcto.
 */
function sentencias_sql(string $sql): array
{
    $limpio = '';

    foreach (preg_split("/\r\n|\n|\r/", $sql) as $linea) {
        $dentro = '';           // '' | "'" | '"' | '`'
        $largo = strlen($linea);
        $corte = $largo;

        for ($i = 0; $i < $largo; $i++) {
            $c = $linea[$i];

            if ($dentro !== '') {
                if ($c === '\\') { $i++; continue; }   // escape dentro de la cadena
                if ($c === $dentro) { $dentro = ''; }
                continue;
            }

            if ($c === "'" || $c === '"' || $c === '`') { $dentro = $c; continue; }
            if ($c === '-' && $i + 1 < $largo && $linea[$i + 1] === '-') { $corte = $i; break; }
        }

        $limpio .= substr($linea, 0, $corte) . "\n";
    }

    $sentencias = [];
    foreach (explode(';', $limpio) as $trozo) {
        $trozo = trim($trozo);
        if ($trozo !== '') {
            $sentencias[] = $trozo;
        }
    }

    return $sentencias;
}

$servidor->exec("DROP DATABASE IF EXISTS " . STOCKIATE_BASE_DEMO);
$servidor->exec("CREATE DATABASE " . STOCKIATE_BASE_DEMO . " CHARACTER SET utf8mb4");
log_demo("Base " . STOCKIATE_BASE_DEMO . " recreada.");

$pdo = conectar_mysql(STOCKIATE_BASE_DEMO);

// La guarda que importa: se la preguntamos al motor, no a nuestras constantes.
$base_real = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($base_real !== STOCKIATE_BASE_DEMO) {
    abortar("La conexión quedó apuntando a '$base_real' y no a '" . STOCKIATE_BASE_DEMO . "'. No se escribió nada.");
}

$tablas = 0;
foreach (sentencias_sql(file_get_contents($ruta_schema)) as $sentencia) {
    // Estas dos apuntan a la base REAL: son la única forma en que este script
    // podría escribir donde no debe. Se descartan siempre.
    if (preg_match('/^\s*(CREATE\s+DATABASE|USE|DROP\s+DATABASE)\b/i', $sentencia)) {
        continue;
    }
    $pdo->exec($sentencia);
    if (stripos($sentencia, 'CREATE TABLE') === 0) {
        $tablas++;
    }
}
log_demo("Esquema aplicado desde schema.sql ($tablas tablas).");

// Segunda verificación, ya con el esquema puesto: si un `USE` se hubiera
// colado, estaríamos parados en otro lado.
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== STOCKIATE_BASE_DEMO) {
    abortar('El esquema movió la conexión de base. Abortado.');
}

// ------------------------------------------------------------------
// 3. Azar reproducible
// ------------------------------------------------------------------
mt_srand(SEMILLA_DEMO);

function rnd(): float
{
    return mt_rand() / mt_getrandmax();
}

/** Float uniforme en [$a, $b]. */
function entre(float $a, float $b): float
{
    return $a + ($b - $a) * rnd();
}

/**
 * Cuántos eventos ocurren en un día si el promedio es $lambda (Knuth).
 * Se usa una Poisson y no un `round($lambda)` porque la gracia de la demo es
 * que los días NO sean todos iguales: con un redondeo, un producto de 0.4/día
 * vendería exactamente 0 todos los días y otro de 3.2 vendería 3 siempre.
 */
function poisson(float $lambda): int
{
    if ($lambda <= 0) return 0;
    $limite = exp(-$lambda);
    $k = 0;
    $p = 1.0;
    do {
        $k++;
        $p *= rnd();
    } while ($p > $limite);
    return $k - 1;
}

$hoy = new DateTimeImmutable('today');

// ------------------------------------------------------------------
// 4. Negocio, usuarios y proveedores
// ------------------------------------------------------------------
require_once __DIR__ . '/configuracion.php';

$pdo->prepare("INSERT INTO negocios (nombre, creado_en) VALUES (:n, :f)")
    ->execute([':n' => NEGOCIO_DEMO_NOMBRE, ':f' => $hoy->modify('-' . (DIAS_HISTORIA + 10) . ' days')->format('Y-m-d H:i:s')]);
$negocio_id = (int) $pdo->lastInsertId();

// OJO con los emails: `demo.*@stockiate.test` son las cuentas del backdoor
// (sesion_demo.php), que las adopta en un negocio llamado 'Negocio Demo'.
// Estas son otras, de otro negocio, para que los dos mecanismos no se pisen.
$CUENTAS = [
    ['dueño',     'Sofía',   'Almada',   'duenio@aroma.demo'],
    ['cajero',    'Martín',  'Quiroga',  'cajero@aroma.demo'],
    ['repositor', 'Luciana', 'Ferreyra', 'repositor@aroma.demo'],
];

$hash = password_hash(PASSWORD_DEMO, PASSWORD_DEFAULT);
$ins_usuario = $pdo->prepare(
    "INSERT INTO usuarios (negocio_id, nombre, apellido, email, password_hash, rol, creado_en)
     VALUES (:negocio_id, :nombre, :apellido, :email, :hash, :rol, :creado_en)"
);

$usuarios = [];
foreach ($CUENTAS as [$rol, $nombre, $apellido, $email]) {
    $ins_usuario->execute([
        ':negocio_id' => $negocio_id,
        ':nombre' => $nombre,
        ':apellido' => $apellido,
        ':email' => $email,
        ':hash' => $hash,
        ':rol' => $rol,
        ':creado_en' => $hoy->modify('-' . (DIAS_HISTORIA + 10) . ' days')->format('Y-m-d H:i:s'),
    ]);
    $usuarios[$rol] = (int) $pdo->lastInsertId();
}

$pdo->prepare("UPDATE negocios SET creado_por = :u WHERE id = :n")
    ->execute([':u' => $usuarios['dueño'], ':n' => $negocio_id]);

sembrar_config_inicial($pdo, $negocio_id);
log_demo("Negocio '" . NEGOCIO_DEMO_NOMBRE . "' con 3 usuarios (contraseña: " . PASSWORD_DEMO . ").");

$PROVEEDORES = [
    'Distribuidora Sur',
    'Consumo Masivo Norte',
    'Cosmética del Plata',
    'Perfumar Mayorista',
    'Dermo Distribuciones',
    'Aromas & Co',
];

$ins_prov = $pdo->prepare("INSERT INTO proveedores (negocio_id, nombre) VALUES (:n, :nom)");
$proveedor_ids = [];
foreach ($PROVEEDORES as $nombre) {
    $ins_prov->execute([':n' => $negocio_id, ':nom' => $nombre]);
    $proveedor_ids[] = (int) $pdo->lastInsertId();
}

// ------------------------------------------------------------------
// 5. Catálogo
// ------------------------------------------------------------------
// [nombre, marca, variante, categoría, precio_venta, índice de proveedor, ritmo]
//
// `ritmo` = unidades por día en promedio, y es LO IMPORTANTE de esta tabla:
// está escrito a mano y no sorteado para garantizar que las rotaciones sean
// MUY distintas entre sí. Si todos los productos rotaran parecido, el panel
// de riesgo de quiebre y cualquier recomendador de ofertas no tendrían nada
// interesante que mostrar. Hay seis en 0.0 a propósito: productos que nunca
// se vendieron, que es justo el caso que interesa detectar.
$CATALOGO = [
    ['Shampoo Hidratación 400ml',          'Sedal',             'Hidratación',   'Cuidado capilar',    4200, 0, 3.20],
    ['Acondicionador Hidratación 400ml',   'Sedal',             'Hidratación',   'Cuidado capilar',    4350, 0, 2.40],
    ['Shampoo Anticaspa 200ml',            'Head & Shoulders',  null,            'Cuidado capilar',    6900, 0, 1.10],
    ['Crema de Peinar Rizos 300ml',        'Pantene',           'Rizos',         'Cuidado capilar',    5600, 0, 0.80],
    ['Tratamiento Capilar Ampollas x6',    'Sedal',             null,            'Cuidado capilar',    7400, 0, 0.25],
    ['Desodorante Aerosol Invisible 150ml','Rexona',            'Invisible',     'Desodorantes',       3800, 1, 4.10],
    ['Desodorante Aerosol Original 150ml', 'Dove',              'Original',      'Desodorantes',       3950, 1, 2.90],
    ['Desodorante Roll-On Dry 50ml',       'Nivea',             null,            'Desodorantes',       3100, 1, 1.60],
    ['Antitranspirante Men Black 150ml',   'Axe',               'Black',         'Desodorantes',       3700, 1, 2.20],
    ['Talco Desodorante 200g',             'Veritas',           null,            'Desodorantes',       2900, 1, 0.00],
    ['Perfume Sauvage EDT 100ml',          'Dior',              null,            'Perfumes',         189000, 3, 0.12],
    ['Perfume Hawas For Him 100ml',        'Rasasi',            null,            'Perfumes',          78000, 3, 0.18],
    ['Perfume La Vie Est Belle 50ml',      'Lancôme',           null,            'Perfumes',         165000, 3, 0.08],
    ['Perfume Blue Seduction 100ml',       'Antonio Banderas',  null,            'Perfumes',          32000, 3, 0.55],
    ['Perfume 212 VIP Men 100ml',          'Carolina Herrera',  'VIP Men',       'Perfumes',         142000, 3, 0.10],
    ['Perfume Good Girl 80ml',             'Carolina Herrera',  'Good Girl',     'Perfumes',         158000, 3, 0.00],
    ['Body Splash Vainilla 250ml',         'Fragance',          'Vainilla',      'Perfumes',           8900, 3, 1.30],
    ['Body Splash Coco 250ml',             'Fragance',          'Coco',          'Perfumes',           8900, 3, 0.95],
    ['Crema Corporal Nutritiva 400ml',     'Nivea',             null,            'Cuidado de la piel', 7200, 4, 1.40],
    ['Crema de Manos Reparadora 75ml',     'Neutrogena',        null,            'Cuidado de la piel', 9800, 4, 0.90],
    ['Protector Solar FPS 50 200ml',       'Dermaglós',         'FPS 50',        'Cuidado de la piel',21500, 4, 0.35],
    ['Gel de Limpieza Facial 150ml',       'Cetaphil',          null,            'Cuidado de la piel',18900, 4, 0.40],
    ['Crema Antiarrugas Noche 50ml',       "L'Oréal",           'Noche',         'Cuidado de la piel',24500, 4, 0.22],
    ['Agua Micelar 400ml',                 'Garnier',           null,            'Cuidado de la piel',11200, 4, 0.75],
    ['Sérum Vitamina C 30ml',              'The Ordinary',      null,            'Cuidado de la piel',27800, 4, 0.30],
    ['Exfoliante Corporal Avena 250ml',    'Natura',            null,            'Cuidado de la piel',13400, 4, 0.00],
    ['Base Líquida Mate Tono 02',          'Maybelline',        'Tono 02',       'Maquillaje',        15800, 2, 0.50],
    ['Base Líquida Mate Tono 05',          'Maybelline',        'Tono 05',       'Maquillaje',        15800, 2, 0.28],
    ['Máscara de Pestañas Volumen',        'Maybelline',        null,            'Maquillaje',        12400, 2, 0.85],
    ['Labial Mate Rojo Clásico',           'Revlon',            'Rojo Clásico',  'Maquillaje',         9600, 2, 0.60],
    ['Labial Mate Nude',                   'Revlon',            'Nude',          'Maquillaje',         9600, 2, 0.45],
    ['Delineador Líquido Negro',           'Avon',              null,            'Maquillaje',         7800, 2, 0.35],
    ['Polvo Compacto Traslúcido',          'Avon',              null,            'Maquillaje',        11900, 2, 0.00],
    ['Esmalte Uñas Rojo Pasión',           'Andrea',            'Rojo Pasión',   'Maquillaje',         3400, 2, 0.70],
    ['Esmalte Uñas Nude',                  'Andrea',            'Nude',          'Maquillaje',         3400, 2, 0.00],
    ['Jabón Líquido Manos 250ml',          'Dove',              null,            'Higiene',            4600, 5, 1.05],
    ['Crema Dental Blanqueadora 90g',      'Colgate',           null,            'Higiene',            3900, 5, 1.80],
    ['Cepillo Dental Suave',               'Oral-B',            null,            'Higiene',            2800, 5, 1.15],
    ['Enjuague Bucal 500ml',               'Listerine',         null,            'Higiene',            8700, 5, 0.65],
    ['Máquina de Afeitar x4',              'Gillette',          null,            'Higiene',            9900, 5, 0.00],
];

// Estados que se fuerzan para que el panel tenga algo que mostrar (1-based,
// posición en $CATALOGO).
$SIN_STOCK = [6, 22, 31, 37];                  // stock_actual = 0
$CRITICOS  = [1, 3, 9, 19, 29, 38];            // 0 < stock_actual < stock_minimo
// Sin precio_costo cargado: en la Red de Seguridad el costo es opcional y con
// el cliente esperando casi nunca se carga. Que falte en algunos es parte del
// caso real, y es lo que ejercita el "cálculo parcial" del chatbot.
$SIN_COSTO = [13, 25, 28, 33, 39];

$prefijos = [
    'Cuidado capilar' => 'CAP', 'Desodorantes' => 'DES', 'Perfumes' => 'PER',
    'Cuidado de la piel' => 'PIE', 'Maquillaje' => 'MAQ', 'Higiene' => 'HIG',
];
$contador_sku = [];

$ins_producto = $pdo->prepare(
    "INSERT INTO productos
        (negocio_id, sku, nombre, marca, variante, categoria, precio_venta, precio_costo,
         proveedor_id, stock_actual, stock_minimo, activo, creado_en)
     VALUES
        (:negocio_id, :sku, :nombre, :marca, :variante, :categoria, :precio_venta, :precio_costo,
         :proveedor_id, 0, :stock_minimo, 1, :creado_en)"
);

$productos = [];   // pos (1-based) => datos + id

foreach ($CATALOGO as $i => [$nombre, $marca, $variante, $categoria, $precio_venta, $prov, $ritmo]) {
    $pos = $i + 1;

    $prefijo = $prefijos[$categoria];
    $contador_sku[$prefijo] = ($contador_sku[$prefijo] ?? 0) + 1;
    $sku = sprintf('%s-%03d', $prefijo, $contador_sku[$prefijo]);

    // Margen entre 35% y 55%, variado producto por producto.
    $margen = entre(0.35, 0.55);
    $precio_costo = in_array($pos, $SIN_COSTO, true) ? null : round($precio_venta * (1 - $margen), 2);

    $stock_minimo = [3, 5, 5, 5, 8, 10][mt_rand(0, 5)];

    // Los productos más viejos que la ventana de historia; unos pocos, más
    // nuevos, para que la antigüedad no sea toda igual.
    $antiguedad = DIAS_HISTORIA + mt_rand(5, 400);
    $creado = $hoy->modify("-$antiguedad days")->setTime(mt_rand(9, 18), mt_rand(0, 59));

    $ins_producto->execute([
        ':negocio_id' => $negocio_id,
        ':sku' => $sku,
        ':nombre' => $nombre,
        ':marca' => $marca,
        ':variante' => $variante,
        ':categoria' => $categoria,
        ':precio_venta' => $precio_venta,
        ':precio_costo' => $precio_costo,
        ':proveedor_id' => $proveedor_ids[$prov],
        ':stock_minimo' => $stock_minimo,
        ':creado_en' => $creado->format('Y-m-d H:i:s'),
    ]);

    $productos[$pos] = [
        'id' => (int) $pdo->lastInsertId(),
        'pos' => $pos,
        'nombre' => $nombre,
        'precio_venta' => (float) $precio_venta,
        'precio_costo' => $precio_costo,
        'ritmo' => (float) $ritmo,
        'stock_minimo' => $stock_minimo,
        'vendido' => 0,
    ];
}

log_demo('Catálogo: ' . count($productos) . ' productos, ' . count($proveedor_ids) . ' proveedores.');

// ------------------------------------------------------------------
// 6. Ventas: 90 días con estacionalidad
// ------------------------------------------------------------------
// Un comercio de barrio no vende parejo: el viernes y el sábado son el doble
// de un lunes, el domingo casi no abre, y a fin de mes (cuando se cobra) hay
// un pico. Sin esto el gráfico de ventas por día sale como una recta y no se
// entiende para qué sirve mirarlo.
const FACTOR_DIA_SEMANA = [
    1 => 0.65,  // lunes
    2 => 0.80,
    3 => 0.90,
    4 => 1.00,
    5 => 1.45,  // viernes
    6 => 1.70,  // sábado
    7 => 0.30,  // domingo
];

$ins_venta = $pdo->prepare(
    "INSERT INTO ventas (negocio_id, producto_id, cantidad, precio_unitario, costo_unitario, usuario_id, fecha)
     VALUES (:negocio_id, :producto_id, :cantidad, :precio, :costo, :usuario_id, :fecha)"
);

$pdo->beginTransaction();

$total_ventas = 0;
$facturacion = 0.0;

for ($d = DIAS_HISTORIA; $d >= 0; $d--) {
    $dia = $hoy->modify("-$d days");
    $factor = FACTOR_DIA_SEMANA[(int) $dia->format('N')];

    // Pico de fin de mes: los últimos cuatro días.
    $ultimo = (int) $dia->format('t');
    if ((int) $dia->format('j') > $ultimo - 4) {
        $factor *= 1.5;
    }

    foreach ($productos as $pos => $p) {
        $eventos = poisson($p['ritmo'] * $factor);

        for ($e = 0; $e < $eventos; $e++) {
            $r = rnd();
            $cantidad = $r < 0.85 ? 1 : ($r < 0.97 ? 2 : 3);

            $hora = mt_rand(9, 20);
            $fecha = $dia->setTime($hora, mt_rand(0, 59), mt_rand(0, 59));

            $ins_venta->execute([
                ':negocio_id' => $negocio_id,
                ':producto_id' => $p['id'],
                ':cantidad' => $cantidad,
                ':precio' => $p['precio_venta'],
                // Snapshot del costo al momento de vender, igual que
                // registrar_venta.php. NULL si el producto no tiene costo.
                ':costo' => $p['precio_costo'],
                ':usuario_id' => $usuarios['cajero'],
                ':fecha' => $fecha->format('Y-m-d H:i:s'),
            ]);

            $productos[$pos]['vendido'] += $cantidad;
            $total_ventas++;
            $facturacion += $cantidad * $p['precio_venta'];
        }
    }
}

$pdo->commit();
log_demo(sprintf(
    'Ventas: %d operaciones en %d días, $%s facturados.',
    $total_ventas, DIAS_HISTORIA, number_format($facturacion, 0, ',', '.')
));

// ------------------------------------------------------------------
// 7. Stock actual y lotes
// ------------------------------------------------------------------
// El stock no se sortea aparte de las ventas: se elige el stock QUE QUEDA hoy
// y de ahí sale cuánto tuvo que entrar (`ingresado = vendido + queda`). Así
// los lotes cuentan una historia consistente con las ventas en vez de ser dos
// conjuntos de números que no cierran entre sí.
$ins_lote = $pdo->prepare(
    "INSERT INTO lotes_stock (negocio_id, producto_id, cantidad, fecha_carga, fecha_vencimiento, usuario_id)
     VALUES (:negocio_id, :producto_id, :cantidad, :carga, :vencimiento, :usuario_id)"
);

// Vencimientos forzados (1-based). Los que vencen pronto tienen que caer
// DENTRO del umbral configurado (30 días por defecto) para que la alerta
// aparezca sola al abrir el panel.
$VENCE_PRONTO = [3 => 9, 21 => 18, 25 => 27];   // pos => días desde hoy
$VENCIDO      = [26 => -12];                    // ya vencido: el caso más urgente

$upd_stock = $pdo->prepare("UPDATE productos SET stock_actual = :s WHERE id = :id AND negocio_id = :n");

$pdo->beginTransaction();
$lotes_creados = 0;

foreach ($productos as $pos => $p) {
    if (in_array($pos, $SIN_STOCK, true)) {
        $queda = 0;
    } elseif (in_array($pos, $CRITICOS, true)) {
        $queda = mt_rand(1, max(1, $p['stock_minimo'] - 1));
    } else {
        // Entre uno y cuatro meses de venta encima del mínimo; los que no
        // rotan quedan con stock parado, que es justo lo que hay que ver.
        $queda = $p['ritmo'] > 0
            ? (int) round($p['stock_minimo'] + $p['ritmo'] * mt_rand(15, 60))
            : mt_rand(4, 14);
    }

    $ingresado = $p['vendido'] + $queda;

    $upd_stock->execute([':s' => $queda, ':id' => $p['id'], ':n' => $negocio_id]);

    if ($ingresado <= 0) {
        continue;
    }

    // Entre 1 y 4 entregas repartidas en la ventana de historia.
    $cuantos = $ingresado > 40 ? mt_rand(3, 4) : ($ingresado > 10 ? mt_rand(2, 3) : 1);
    $restante = $ingresado;

    for ($l = 0; $l < $cuantos; $l++) {
        $ultimo_lote = ($l === $cuantos - 1);
        $cantidad = $ultimo_lote ? $restante : max(1, (int) round($ingresado / $cuantos * entre(0.7, 1.3)));
        $cantidad = min($cantidad, $restante);
        $restante -= $cantidad;
        if ($cantidad <= 0) continue;

        // Las entregas se reparten hacia atrás: la primera al principio de la
        // ventana, las siguientes escalonadas.
        $dias_atras = (int) round(DIAS_HISTORIA - ($l * DIAS_HISTORIA / max(1, $cuantos)) - mt_rand(0, 6));
        $dias_atras = max(0, min(DIAS_HISTORIA + 5, $dias_atras));
        $carga = $hoy->modify("-$dias_atras days")->setTime(mt_rand(8, 12), mt_rand(0, 59));

        // Vencimiento: la mayoría sin fecha (como en la realidad — en la Red
        // de Seguridad es un campo opcional que casi nadie completa), salvo
        // los casos forzados de arriba.
        $vencimiento = null;
        if ($ultimo_lote && isset($VENCE_PRONTO[$pos])) {
            $vencimiento = $hoy->modify('+' . $VENCE_PRONTO[$pos] . ' days')->format('Y-m-d');
        } elseif ($ultimo_lote && isset($VENCIDO[$pos])) {
            $vencimiento = $hoy->modify($VENCIDO[$pos] . ' days')->format('Y-m-d');
        } elseif (rnd() < 0.35) {
            $vencimiento = $hoy->modify('+' . mt_rand(180, 540) . ' days')->format('Y-m-d');
        }

        $ins_lote->execute([
            ':negocio_id' => $negocio_id,
            ':producto_id' => $p['id'],
            ':cantidad' => $cantidad,
            ':carga' => $carga->format('Y-m-d H:i:s'),
            ':vencimiento' => $vencimiento,
            ':usuario_id' => $usuarios['repositor'],
        ]);
        $lotes_creados++;
    }
}

$pdo->commit();
log_demo(sprintf(
    'Stock: %d lotes. %d productos sin stock, %d en crítico.',
    $lotes_creados, count($SIN_STOCK), count($CRITICOS)
));

// ------------------------------------------------------------------
// 8. Correcciones de la IA
// ------------------------------------------------------------------
// La mezcla NO es al azar: reproduce el error típico del caso real, que es
// confundir dos VARIANTES DEL MISMO ENVASE (el shampoo con el acondicionador
// de la misma línea, el labial rojo con el nude). Un modelo que confunde un
// perfume con un cepillo de dientes no se parece en nada a lo que pasa.
//
// Y sobre todo: las confianzas CORRELACIONAN con el acierto (aciertos altos,
// errores bajos). Es la relación que la pantalla de métricas va a medir, y si
// los números fueran independientes esa pantalla mostraría una conclusión
// falsa sobre datos inventados.
$CONFUNDIBLES = [
    [1, 2],    // Sedal shampoo / acondicionador
    [6, 7],    // Rexona / Dove aerosol
    [17, 18],  // Body splash vainilla / coco
    [27, 28],  // Base tono 02 / tono 05
    [30, 31],  // Labial rojo / nude
    [34, 35],  // Esmalte rojo / nude
];

const CORRECCIONES_TOTAL = 60;

$ins_correccion = $pdo->prepare(
    "INSERT INTO correcciones_ia
        (negocio_id, producto_detectado_id, producto_corregido_id,
         cantidad_detectada, cantidad_corregida, confianza_ia, usuario_id, fecha)
     VALUES
        (:negocio_id, :detectado, :corregido, :cant_det, :cant_cor, :confianza, :usuario_id, :fecha)"
);

// 43 aciertos + 9 producto mal + 6 cantidad mal + 2 no reconocido = 60.
//
// Las dos filas de "no reconocido" (producto_detectado_id NULL) no estaban en
// el pedido original, que repartía 75/15/10. Van igual porque el caso existe y
// es frecuente en el sistema real -- la detección llega con `identificado:
// false` y la persona elige el producto a mano -- y porque es una categoría
// aparte de "se equivocó de producto": si no hubiera ninguna, la pantalla de
// métricas no podría mostrar esa distinción sobre datos de demo.
$plan = array_merge(
    array_fill(0, 43, 'ok'),
    array_fill(0, 9, 'producto'),
    array_fill(0, 6, 'cantidad'),
    array_fill(0, 2, 'no_reconocido')
);

// Barajado determinístico: shuffle() usa el mismo mt_rand ya sembrado.
shuffle($plan);

$pdo->beginTransaction();

foreach ($plan as $i => $caso) {
    // Repartidas por la ventana de historia, así el acierto por semana tiene
    // varias semanas que mostrar.
    $dias_atras = (int) round(DIAS_HISTORIA * $i / CORRECCIONES_TOTAL) + mt_rand(0, 2);
    $fecha = $hoy->modify('-' . min(DIAS_HISTORIA, $dias_atras) . ' days')
                 ->setTime(mt_rand(9, 19), mt_rand(0, 59));

    $cantidad = mt_rand(1, 6);

    if ($caso === 'producto') {
        $par = $CONFUNDIBLES[mt_rand(0, count($CONFUNDIBLES) - 1)];
        if (mt_rand(0, 1) === 1) {
            $par = array_reverse($par);
        }
        $detectado = $productos[$par[0]]['id'];
        $corregido = $productos[$par[1]]['id'];
        $cant_det = $cantidad;
        $cant_cor = $cantidad;
        $confianza = entre(0.45, 0.70);
    } elseif ($caso === 'cantidad') {
        $p = $productos[mt_rand(1, count($productos))];
        $detectado = $p['id'];
        $corregido = $p['id'];
        // Contar de menos es lo habitual: los envases de atrás quedan tapados.
        $cant_cor = $cantidad;
        $cant_det = max(1, $cantidad - mt_rand(1, 2));
        $confianza = entre(0.45, 0.70);
    } elseif ($caso === 'no_reconocido') {
        $p = $productos[mt_rand(1, count($productos))];
        $detectado = null;                 // el OCR no leyó la etiqueta
        $corregido = $p['id'];             // la persona lo eligió del catálogo
        $cant_det = $cantidad;
        $cant_cor = $cantidad;
        $confianza = entre(0.40, 0.60);
    } else {
        $p = $productos[mt_rand(1, count($productos))];
        $detectado = $p['id'];
        $corregido = $p['id'];
        $cant_det = $cantidad;
        $cant_cor = $cantidad;
        $confianza = entre(0.85, 0.95);
    }

    $ins_correccion->execute([
        ':negocio_id' => $negocio_id,
        ':detectado' => $detectado,
        ':corregido' => $corregido,
        ':cant_det' => $cant_det,
        ':cant_cor' => $cant_cor,
        ':confianza' => round($confianza, 2),
        ':usuario_id' => $usuarios['repositor'],
        ':fecha' => $fecha->format('Y-m-d H:i:s'),
    ]);
}

$pdo->commit();
log_demo('Correcciones de IA: ' . CORRECCIONES_TOTAL . ' registros (43 aciertos, 9 producto mal, 6 cantidad mal, 2 sin reconocer).');

// ------------------------------------------------------------------
// 9. Cierre
// ------------------------------------------------------------------
log_demo('');
log_demo('Listo. Entrá con cualquiera de estas cuentas (contraseña: ' . PASSWORD_DEMO . '):');
foreach ($CUENTAS as [$rol, $nombre, $apellido, $email]) {
    log_demo(sprintf('  %-10s %s', $rol, $email));
}

if (!$es_cli) {
    responder([
        'ok' => true,
        'mensaje' => 'Datos de demo regenerados.',
        'detalle' => $registro,
    ]);
}
