<?php

declare(strict_types=1);

namespace Laminas\Router\Http;

use Laminas\Router\RouteInterface;
use Laminas\Stdlib\RequestInterface;

/**
 * Tree specific route interface.
 *
 * Note: the additional {@see self::match()} annotation is only here for documentation purposes, because we cannot
 *       change the signature of {@see self::match()} in the interface definition without breaking BC.
 *
 * @method RouteMatch|null match(RequestInterface $request, int|null $pathOffset = null, array $options = [])
 */
interface HttpRouteInterface extends RouteInterface
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
