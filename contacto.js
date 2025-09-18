document.addEventListener('DOMContentLoaded', () => { 
  const form = document.getElementById('form-contacto');
  if (form) {
    form.addEventListener('submit', validarFormularioContacto);

    // Listeners para limpiar error y estilos al corregir
    ['nombre', 'email', 'mensaje'].forEach(id => {
      const input = document.getElementById(id);
      if (input) {
        input.addEventListener('input', () => {
          limpiarMensajeError(input);
          marcarCampo(input, true);
        });
      }
    });
  }
});

function validarFormularioContacto(event) {
  event.preventDefault();

  const nombreInput = document.getElementById('nombre');
  const emailInput = document.getElementById('email');
  const mensajeInput = document.getElementById('mensaje');

  limpiarMensajesError([nombreInput, emailInput, mensajeInput]);

  let valido = true;

  // Validación de campos vacíos
  if (!nombreInput.value.trim()) {
    mostrarMensajeError(nombreInput, 'El nombre es obligatorio.');
    marcarCampo(nombreInput, false);
    valido = false;
  } else {
    marcarCampo(nombreInput, true);
  }

  if (!emailInput.value.trim()) {
    mostrarMensajeError(emailInput, 'El correo es obligatorio.');
    marcarCampo(emailInput, false);
    valido = false;
  } else {
    marcarCampo(emailInput, true);
  }

  if (!mensajeInput.value.trim()) {
    mostrarMensajeError(mensajeInput, 'El mensaje es obligatorio.');
    marcarCampo(mensajeInput, false);
    valido = false; 
  } else {
    marcarCampo(mensajeInput, true);
  }

  if (!valido) {
    mostrarNotificacion('advertencia', 'Por favor, completa todos los campos.');
    return false;
  }

  // Validación de email
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  if (!emailRegex.test(emailInput.value.trim())) {
    marcarCampo(emailInput, false);
    mostrarNotificacion('error', 'Por favor, ingresa un correo electrónico válido.');
    return false;
  } else {
    marcarCampo(emailInput, true);
  }

  // Validación de mensaje mínimo
  const palabras = mensajeInput.value.trim().split(/\s+/);
  if (palabras.length < 10) {
    marcarCampo(mensajeInput, false);
    mostrarNotificacion('advertencia', 'El mensaje debe contener al menos 10 palabras.');
    mensajeInput.focus();
    return false;
  } else {
    marcarCampo(mensajeInput, true);
  }

  mostrarNotificacion('exito', '¡Mensaje enviado correctamente!', true);
  document.getElementById('form-contacto').reset();
  limpiarCampos([nombreInput, emailInput, mensajeInput]);
  limpiarMensajesError([nombreInput, emailInput, mensajeInput]);
  return true;
}

// Marca el campo como válido o inválido visualmente usando clases
function marcarCampo(input, esValido) {
  if (!input) return;
  input.classList.remove('campo-error', 'campo-valido');
  if (esValido) {
    input.classList.add('campo-valido');
  } else {
    input.classList.add('campo-error');
  }
}

// Limpia los estilos de los campos
function limpiarCampos(inputs) {
  inputs.forEach(input => {
    if (input) input.classList.remove('campo-error', 'campo-valido');
  });
}

// Limpia el mensaje de error de un solo campo
function limpiarMensajeError(input) {
  const span = input.parentNode.querySelector('.error-mensaje');
  if (span) span.remove();
}

// Limpia los mensajes de error de todos los campos
function limpiarMensajesError(inputs) {
  inputs.forEach(input => limpiarMensajeError(input));
}

function mostrarMensajeError( input, mensaje ) {
  const errorExistente = input.parentNode.querySelector('.mensaje-error');
  if (errorExistente) errorExistente.remove();
  const error = document.createElement('span');
  error.className = 'error-mensaje';
  error.textContent = mensaje;
   input.parentNode.insertBefore(error, input.nextSibling);
  }

function mostrarNotificacion(tipo, mensaje, reproducirSonido = false) {
  const notificacion = document.getElementById('notificacion-exito');
  if (!notificacion) return;

  notificacion.className = 'notificacion';
  notificacion.classList.add(tipo, 'activa');

  const texto = notificacion.querySelector('p');
  if (texto) texto.textContent = mensaje;

  if (reproducirSonido) {
    const sonido = document.getElementById('sonido-exito');
    if (sonido) sonido.play();
  }

  const cerrar = notificacion.querySelector('.cerrar-notificacion');
  if (cerrar) {
    cerrar.onclick = () => {
      notificacion.classList.remove('activa');
    };
  }

  setTimeout(() => {
    if (notificacion.classList.contains('activa')) {
      notificacion.classList.remove('activa');
    }
  }, 5000);
}