<?php

declare(strict_types=1);

namespace Laminas\Router\Http;

/**
 * @deprecated Use HttpRouteInterface instead; this will be removed in v4.0
 */
interface RouteInterface extends HttpRouteInterface
{
    /**
     * Get a list of parameters used while assembling.
     *
     * @deprecated Since 3.19.0. This method will be removed in 4.0 and assembled parameters
     * will be available on the value object that will be returned from assemble().
     * There is not a forward compatible way to replace usage of this method.
     *
     * @return array
     */
    public function getAssembledParams();
}
