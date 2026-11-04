<?php

namespace Webkul\Admin\Exceptions;

use App\Exceptions\Handler as AppExceptionHandler;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends AppExceptionHandler
{
    /**
     * Json error messages.
     *
     * @var array
     */
    protected $jsonErrorMessages = [];

    /**
     * Create handler instance.
     *
     * @return void
     */
    public function __construct(Container $container)
    {
        parent::__construct($container);

        $this->jsonErrorMessages = [
            '404' => $this->errorMessage('admin::app.common.resource-not-found', 'Resource not found.'),
            '403' => $this->errorMessage('admin::app.common.forbidden-error', 'This action is unauthorized.'),
            '401' => $this->errorMessage('admin::app.common.unauthenticated', 'Unauthenticated.'),
            '500' => $this->errorMessage('admin::app.common.internal-server-error', 'Server error.'),
        ];
    }

    /**
     * Mensaje traducido, con fallback en texto plano si la clave i18n no resuelve
     * (en el grupo de rutas `api` el namespace `admin::` puede no estar cargado y
     * `trans()` devolvería la clave cruda).
     */
    private function errorMessage(string $key, string $fallback): string
    {
        $translated = trans($key);

        return $translated === $key ? $fallback : $translated;
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function render($request, Throwable $exception)
    {
        if (! config('app.debug')) {
            return $this->renderCustomResponse($exception);
        }

        return parent::render($request, $exception);
    }

    /**
     * Convert an authentication exception into a response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->jsonErrorMessages[401]], 401);
        }

        return redirect()->guest(route('customer.session.index'));
    }

    /**
     * Render custom HTTP response.
     *
     * @return \Illuminate\Http\Response|null
     */
    private function renderCustomResponse(Throwable $exception)
    {
        // Auth fallida/ausente debe ser 401 (no 500). En producción (app.debug=false)
        // render() entra directo acá y saltea el unauthenticated() del handler base,
        // así que hay que contemplar el caso explícitamente para la API con token.
        if ($exception instanceof AuthenticationException) {
            if (request()->expectsJson()) {
                return response()->json(['message' => $this->jsonErrorMessages[401]], 401);
            }

            return redirect()->guest(route('customer.session.index'));
        }

        if ($exception instanceof HttpException) {
            $statusCode = in_array($exception->getStatusCode(), [401, 403, 404, 503])
                ? $exception->getStatusCode()
                : 500;

            return $this->response($statusCode);
        }

        if ($exception instanceof ValidationException) {
            return parent::render(request(), $exception);
        }

        if ($exception instanceof ModelNotFoundException) {
            return $this->response(404);
        } elseif ($exception instanceof PDOException || $exception instanceof \ParseError) {
            return $this->response(500);
        } else {
            return $this->response(500);
        }
    }

    /**
     * Return custom response.
     *
     * @param  string  $path
     * @param  string  $errorCode
     * @return mixed
     */
    private function response($errorCode)
    {
        if (request()->expectsJson()) {
            return response()->json([
                'message' => isset($this->jsonErrorMessages[$errorCode])
                    ? $this->jsonErrorMessages[$errorCode]
                    : trans('admin::app.common.something-went-wrong'),
            ], $errorCode);
        }

        return response()->view('admin::errors.index', compact('errorCode'));
    }
}
