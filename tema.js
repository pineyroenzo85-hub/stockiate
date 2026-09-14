// Se carga en el <head>, antes del primer paint, para evitar el flash de
// tema incorrecto: si hay preferencia guardada la aplica; si no, usa la del
// sistema operativo (prefers-color-scheme). El CSS ya sabe respetar el
// sistema por su cuenta -- este script solo entra en juego para el override
// manual, y persiste esa elección en localStorage.
(function () {
  var CLAVE = 'stockiate_tema';

  function aplicar(tema) {
    if (tema === 'claro' || tema === 'oscuro') {
      document.documentElement.setAttribute('data-tema', tema);
    } else {
      document.documentElement.removeAttribute('data-tema');
    }
  }

  var guardado = null;
  try { guardado = localStorage.getItem(CLAVE); } catch (e) {}
  aplicar(guardado);

  window.stockiateTema = {
    actual: function () {
      if (guardado === 'claro' || guardado === 'oscuro') return guardado;
      var sistemaOscuro = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
      return sistemaOscuro ? 'oscuro' : 'claro';
    },
    alternar: function () {
      var nuevo = this.actual() === 'oscuro' ? 'claro' : 'oscuro';
      guardado = nuevo;
      try { localStorage.setItem(CLAVE, nuevo); } catch (e) {}
      aplicar(nuevo);
    }
  };
})();
