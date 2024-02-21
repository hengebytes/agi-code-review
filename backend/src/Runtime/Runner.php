<?php

namespace App\Runtime;

use Nyholm\Psr7;
use Spiral\RoadRunner;
use Symfony\Bridge\PsrHttpMessage\Factory\{HttpFoundationFactory, PsrHttpFactory};
use Symfony\Component\HttpKernel\{HttpKernelInterface, TerminableInterface};
use Symfony\Component\Runtime\RunnerInterface;
use Throwable;

class Runner implements RunnerInterface
{
    private HttpFoundationFactory $httpFoundationFactory;
    private PsrHttpFactory $httpMessageFactory;
    private Psr7\Factory\Psr17Factory $psrFactory;

    public function __construct(private readonly HttpKernelInterface $kernel)
    {
        $this->psrFactory = new Psr7\Factory\Psr17Factory();
        $this->httpFoundationFactory = new HttpFoundationFactory();
        $this->httpMessageFactory = new PsrHttpFactory($this->psrFactory, $this->psrFactory, $this->psrFactory, $this->psrFactory);
    }

    public function run(): int
    {
        $worker = RoadRunner\Worker::create();
        $worker = new RoadRunner\Http\PSR7Worker($worker, $this->psrFactory, $this->psrFactory, $this->psrFactory);

        while ($request = $worker->waitRequest()) {
            $data = $request->getParsedBody();
            //if (isset($data['operations'], $data['map'])) {
            //    krsort($data); // fix bug in roadrunner buffer and graphql bundle
            //    $request = $request->withParsedBody($data);
            //}

            try {
                $sfRequest = $this->httpFoundationFactory->createRequest($request);
                $sfResponse = $this->kernel->handle($sfRequest);
                $worker->respond($this->httpMessageFactory->createResponse($sfResponse));

                if ($this->kernel instanceof TerminableInterface) {
                    $this->kernel->terminate($sfRequest, $sfResponse);
                }
            } catch (Throwable $e) {
                $worker->getWorker()->error((string)$e);
            }
        }

        return 0;
    }
}
