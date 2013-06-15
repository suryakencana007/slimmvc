<?php
/**
 * Slim - a micro PHP 5 framework
 *
 * @author      Josh Lockhart <info@slimframework.com>
 * @copyright   2011 Josh Lockhart
 * @link        http://www.slimframework.com
 * @license     http://www.slimframework.com/license
 * @version     2.2.0
 * @package     Slim
 *
 * MIT LICENSE
 *
 * Permission is hereby granted, free of charge, to any person obtaining
 * a copy of this software and associated documentation files (the
 * "Software"), to deal in the Software without restriction, including
 * without limitation the rights to use, copy, modify, merge, publish,
 * distribute, sublicense, and/or sell copies of the Software, and to
 * permit persons to whom the Software is furnished to do so, subject to
 * the following conditions:
 *
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
 * NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
 * LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
 * OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
 * WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */
namespace Slim;

/**
 * Router
 *
 * This class organizes, iterates, and dispatches \Slim\Route objects.
 *
 * @package Slim
 * @author  Josh Lockhart
 * @since   1.0.0
 */
class Router
{
    /**
     * @var Route The current route (most recently dispatched)
     */
    protected $currentRoute;

    /**
     * @var array Lookup hash of all route objects
     */
    protected $routes;

    /**
     * @var array Lookup hash of named route objects, keyed by route name (lazy-loaded)
     */
    protected $namedRoutes;

    /**
     * @var array Array of route objects that match the request URI (lazy-loaded)
     */
    protected $matchedRoutes;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->routes = array();
    }

    /**
     * Get Current Route object or the first matched one if matching has been performed
     * @return \Slim\Route|null
     */
    public function getCurrentRoute()
    {
        echo "getCurrentRoute";
        if ($this->currentRoute !== null) {
            return $this->currentRoute;
        }

        if (is_array($this->matchedRoutes) && count($this->matchedRoutes) > 0) {
            return $this->matchedRoutes[0];
        }

        return null;
    }

    /**
     * Return route objects that match the given HTTP method and URI
     * @param  string               $httpMethod   The HTTP method to match against
     * @param  string               $resourceUri  The resource URI to match against
     * @param  bool                 $reload       Should matching routes be re-parsed?
     * @return array[\Slim\Route]
     */
    public function getMatchedRoutes($httpMethod, $resourceUri, $reload = false)
    {
        if ($reload || is_null($this->matchedRoutes)) {
            $this->matchedRoutes = array();
            foreach ($this->routes as $route) {
                if (!$route->supportsHttpMethod($httpMethod)) {
                    continue;
                }

                if ($route->matches($resourceUri)) {
                    $this->matchedRoutes[] = $route;
                }
            }
        }

        return $this->matchedRoutes;
    }

    /**
     * Map a route object to a callback function
     * @param  string     $pattern      The URL pattern (ie. "/books/:id")
     * @param  mixed      $callable     Anything that returns TRUE for is_callable()
     * @return \Slim\Route
     */
    public function map($pattern, $callable)
    {
        $route = new \Slim\Route($pattern, $callable);
        $this->routes[] = $route;

        return $route;
    }

    /**
     * Get URL for named route
     * @param  string               $name   The name of the route
     * @param  array                Associative array of URL parameter names and replacement values
     * @throws RuntimeException     If named route not found
     * @return string               The URL for the given route populated with provided replacement values
     */
    public function urlFor($name, $params = array())
    {
        if (!$this->hasNamedRoute($name)) {
            throw new \RuntimeException('Named route not found for name: ' . $name);
        }
        $search = array();
        foreach (array_keys($params) as $key) {
            $search[] = '#:' . $key . '\+?(?!\w)#';
        }
        $pattern = preg_replace($search, $params, $this->getNamedRoute($name)->getPattern());

        //Remove remnants of unpopulated, trailing optional pattern segments
        return preg_replace('#\(/?:.+\)|\(|\)#', '', $pattern);
    }

    /**
     * Dispatch route
     *
     * This method invokes the route object's callable. If middleware is
     * registered for the route, each callable middleware is invoked in
     * the order specified.
     *
     * @param  \Slim\Route                  $route  The route object
     * @return bool                         Was route callable invoked successfully?
     */
    public function dispatch(\Slim\Route $route)
    {
        $this->currentRoute = $route; 

        //Invoke middleware
        foreach ($route->getMiddleware() as $mw) {
            call_user_func_array($mw, array($route));
        }

        //Invoke callable
        call_user_func_array($route->getCallable(), array_values($route->getParams()));

        return true;
    }

    /**
     * Add named route
     * @param  string            $name   The route name
     * @param  \Slim\Route       $route  The route object
     * @throws \RuntimeException If a named route already exists with the same name
     */
    public function addNamedRoute($name, \Slim\Route $route)
    {
        if ($this->hasNamedRoute($name)) {
            throw new \RuntimeException('Named route already exists with name: ' . $name);
        }
        $this->namedRoutes[(string) $name] = $route;
    }

    /**
     * Has named route
     * @param  string   $name   The route name
     * @return bool
     */
    public function hasNamedRoute($name)
    {
        $this->getNamedRoutes();

        return isset($this->namedRoutes[(string) $name]);
    }

    /**
     * Get named route
     * @param  string           $name
     * @return \Slim\Route|null
     */
    public function getNamedRoute($name)
    {
        $this->getNamedRoutes();
        if ($this->hasNamedRoute($name)) {
            return $this->namedRoutes[(string) $name];
        } else {
            return null;
        }
    }

    /**
     * Get named routes
     * @return \ArrayIterator
     */
    public function getNamedRoutes()
    {
        if (is_null($this->namedRoutes)) {
            $this->namedRoutes = array();
            foreach ($this->routes as $route) {
                if ($route->getName() !== null) {
                    $this->addNamedRoute($route->getName(), $route);
                }
            }
        }

        return new \ArrayIterator($this->namedRoutes);
    }

     /********************************************************************************
    * Routing
    *******************************************************************************/

    /**
     * Add GET|POST|PUT|DELETE route
     *
     * Adds a new route to the router with associated callable. This
     * route will only be invoked when the HTTP request's method matches
     * this route's method.
     *
     * ARGUMENTS:
     *
     * First:       string  The URL pattern (REQUIRED)
     * In-Between:  mixed   Anything that returns TRUE for `is_callable` (OPTIONAL)
     * Last:        mixed   Anything that returns TRUE for `is_callable` (REQUIRED)
     *
     * The first argument is required and must always be the
     * route pattern (ie. '/books/:id').
     *
     * The last argument is required and must always be the callable object
     * to be invoked when the route matches an HTTP request.
     *
     * You may also provide an unlimited number of in-between arguments;
     * each interior argument must be callable and will be invoked in the
     * order specified before the route's callable is invoked.
     *
     * USAGE:
     *
     * Slim::get('/foo'[, middleware, middleware, ...], callable);
     *
     * @param   array (See notes above)
     * @return  \Slim\Route
     */
    protected function mapRoute($args)
    {
        $pattern = array_shift($args);
        $callable = array_pop($args);
        $route = $this->map($pattern, $callable);
        if (count($args) > 0) {
            $route->setMiddleware($args);
        }

        return $route;
    }

    /**
     * Add generic route without associated HTTP method
     * @see    mapRoute()
     * @return \Slim\Route
     */
    public function maps()
    {
        $args = func_get_args();

        return $this->mapRoute($args);
    }

    /**
     * Add GET route
     * @see    mapRoute()
     * @return \Slim\Route
     */
    public function get()
    {
        $args = func_get_args();

        return $this->mapRoute($args)->via(\Slim\Http\Request::METHOD_GET, \Slim\Http\Request::METHOD_HEAD);
    }

    /**
     * Add POST route
     * @see    mapRoute()
     * @return \Slim\Route
     */
    public function post()
    {
        $args = func_get_args();

        return $this->mapRoute($args)->via(\Slim\Http\Request::METHOD_POST);
    }

    /**
     * Add PUT route
     * @see    mapRoute()
     * @return \Slim\Route
     */
    public function put()
    {
        $args = func_get_args();

        return $this->mapRoute($args)->via(\Slim\Http\Request::METHOD_PUT);
    }

    /**
     * Add DELETE route
     * @see    mapRoute()
     * @return \Slim\Route
     */
    public function delete()
    {
        $args = func_get_args();

        return $this->mapRoute($args)->via(\Slim\Http\Request::METHOD_DELETE);
    }

    /**
     * Add OPTIONS route
     * @see    mapRoute()
     * @return \Slim\Route
     */
    public function options()
    {
        $args = func_get_args();

        return $this->mapRoute($args)->via(\Slim\Http\Request::METHOD_OPTIONS);
    }

    /**
    *Add CONTROLLERS route
    * ('/user','User@index')->name('user.index')
    * @see  mapRoute()
    * @return \Slim\Route
    */
    public function controller()
    {
        $args = func_get_args();
        $arrArgs = $this->makeController($args);
        return $this->mapRoute($arrArgs['args'])->via(\Slim\Http\Request::METHOD_GET, \Slim\Http\Request::METHOD_HEAD)->name($arrArgs['name']);
    }

    private function makeController($args) 
    {
        //validasi args kesalahan input fucking user
        $uriGen = array();

        $uriGen['path'] = array_shift($args);
        $uriGen['controller'] = array_pop($args);

        if(is_callable($uriGen['controller'])){
            throw new \Exception("this callable arguments, please input controller RouteName as like => home@index");
        }
        //array callable
        $uriGen['crtlact'] = explode('@', $uriGen['controller']);
        $uriGen['name'] = $uriGen['crtlact'][0].".".$uriGen['crtlact'][1];
        //generate controller class
        $uriGen['class'] = ucfirst($uriGen['crtlact'][0]).'_Controller';
        $instanceCon = new $uriGen['class'];
        //------

        //var_dump($instanceCon);
        $uriGen['args'] = array($uriGen['path'],array($instanceCon,$uriGen['crtlact'][1]));
        return $uriGen;
    }
}
