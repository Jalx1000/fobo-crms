/**
 * We'll load the axios HTTP library which allows us to easily issue requests
 * to our Laravel back-end. This library automatically handles sending the
 * CSRF token as a header based on the value of the "XSRF" token cookie.
 */
import axios from "axios";
window.axios = axios;
window.axios.defaults.headers.common["X-Requested-With"] = "XMLHttpRequest";

/**
 * Sesión vencida: recargar la página, que sin sesión termina en el login.
 *
 * 419 es el token CSRF de una sesión que ya no existe; el 401 con
 * X-Sesion-Vencida lo manda el middleware `user`. Un 401 sin ese encabezado es
 * "no tenés permiso" y lo maneja cada pantalla.
 */
window.axios.interceptors.response.use(
    (response) => response,
    (error) => {
        const response = error.response;

        if (
            response &&
            (response.status === 419 ||
                (response.status === 401 && response.headers["x-sesion-vencida"]))
        ) {
            window.location.reload();
        }

        return Promise.reject(error);
    }
);

export default {
    install(app) {
        app.config.globalProperties.$axios = axios;
    },
};
