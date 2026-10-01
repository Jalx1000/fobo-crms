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
            '404' => trans('admin::app.common.resource-not-found'),
            '403' => trans('admin::app.common.forbidden-error'),
            '401' => trans('admin::app.common.unauthenticated'),
            '429' => trans('admin::app.common.too-many-requests'),
            '500' => trans('admin::app.common.internal-server-error'),
        ];
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
        if ($exception instanceof HttpException) {
            /**
             * A 4xx is a deliberate answer to the caller, so it survives intact —
             * along with its headers. Rewriting 429 to a bare 500 (the old
             * behaviour for anything outside a short allowlist) told API clients
             * "the CRM is broken" instead of "slow down", and threw away the
             * Retry-After header they need in order to do so. Server faults still
             * collapse to a generic 500: their detail is nobody's business.
             */
            $statusCode = $exception->getStatusCode();

            if ($statusCode < 400 || $statusCode >= 500) {
                $statusCode = $statusCode === 503 ? 503 : 500;
            }

            return $this->response($statusCode, $exception->getHeaders());
        }

        if ($exception instanceof ValidationException) {
            return parent::render(request(), $exception);
        }

        /**
         * A bad or expired token is a 401, not a server fault. Only for JSON:
         * the web branch of unauthenticated() redirects to a login route that
         * does not exist in this app, so it is left exactly as it was.
         */
        if (
            $exception instanceof AuthenticationException
            && request()->expectsJson()
        ) {
            return $this->response(401);
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
     * @param  string  $errorCode
     * @param  array  $headers  Headers the exception itself carries (Retry-After, ...).
     * @return mixed
     */
    private function response($errorCode, array $headers = [])
    {
        if (request()->expectsJson()) {
            return response()->json([
                'message' => isset($this->jsonErrorMessages[$errorCode])
                    ? $this->jsonErrorMessages[$errorCode]
                    : trans('admin::app.common.something-went-wrong'),
            ], $errorCode, $headers);
        }

        return response()->view('admin::errors.index', compact('errorCode'), $errorCode, $headers);
    }
}
