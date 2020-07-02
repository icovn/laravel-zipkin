<?php

namespace Mts88\LaravelZipkin\Middlewares;

use Closure;
use Illuminate\Support\Facades\Auth;
use Mts88\LaravelZipkin\Services\ZipkinService;
use Zipkin\Propagation\Map;

class ZipkinRequestLogger
{
    private $zipkinService;

    public function __construct(ZipkinService $zipkinService)
    {
        $this->zipkinService = $zipkinService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {

        if (in_array($request->method(), $this->zipkinService->getAllowedMethods())) {
            // create tracing
            $tracing = $this->zipkinService->createTracing($request->route()->getName(), $request->ip());

            // extract the context from HTTP headers
            $extractor = $tracing->getPropagation()->getExtractor(new Map());
            $carrier = array_map(function ($header) {
                return $header[0];
            }, $request->headers->all());
            $extractedContext = $extractor($carrier);

            // create root span
            $tracer = $tracing->getTracer();
            $span = $tracer->nextSpan($extractedContext);
            $span->start(\Zipkin\Timestamp\now());
            $span->setName($request->getUri());

            // set root span
            $this->zipkinService->setRootSpan($span)
                ->setRootSpanMethod($request->method())
                ->setRootSpanPath($request->path())
                ->setRootAuthUser(Auth::user())
                ->setRootSpanTag('request.headers', json_encode($request->headers->all()))
                ->setRootSpanTag('request.body', json_encode($request->all()));
        }

        return $next($request);
    }

    public function terminate($request, $response)
    {

        if (!is_null($this->zipkinService->getRootSpan())) {
            $this->zipkinService->setRootSpanStatusCode($response->getStatusCode())->closeSpan();
        }

    }

}
