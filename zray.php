<?php

namespace ZRay;

class Symfony {
	/**
	 * @var \Symfony\Component\HttpKernel\Kernel
	 */
	private $kernel;
	private $tracedAlready = false;
	private $zre = null;

	public function setZRE($zre) {
		$this->zre = $zre;
	}


	public function eventDispatchExit($context, &$storage) {
		$event = $context['functionArgs'][1];
		$storage['events'][] = array(	
						'name' => $event->getName(),
						'type' => get_class($event),
						'dispatcher' => get_class($event->getDispatcher()),
						'propagation stopped' => $event->isPropagationStopped(),
						);
	}

	public function registerBundlesExit($context, &$storage) {
		$bundles = $context['returnValue'];

		foreach ($bundles as $bundle) {
			$storage['bundles'][] = array(
							'name' => $bundle->getName(),
							'namespace' => $bundle->getNamespace(),
							'container' => get_class($bundle->getContainerExtension()),
							'path' => $bundle->getPath(),
						);
		}
	}

	public function handleRequestExit($context, &$storage) {
		$request = $context['functionArgs'][0];
		
		$ctrl = $request->get('_controller');
		if (empty($ctrl)) {
			return;
		}
		$ctrl = explode(':', $ctrl);
		$controller = $ctrl[0];
		if (!empty($ctrl[2])) {
			$action = $ctrl[2];
		} else {
			$action = $ctrl[1];
		}
		try {
			$refclass = new \ReflectionClass($controller);
			$filename = $refclass->getFileName();
			$lineno = $refclass->getStartLine();
		} catch (Exception $e) {
			$filename = $lineno = '';
		}
		$storage['request'][] = array(
						'Controller' => $controller,
						'Action' => $action,
						'Filename' => $filename,
						'Line Number' => $lineno,
						'Route' => array(	
							'Name' => $request->get('_route'),
							'Params' => $request->get('_routeparams'),
							),
						'Session' => ($request->getSession() ? 'yes' : 'no'),
						'Locale' => $request->getLocale(),
					);
	}

	public function terminateExit($context, &$storage){
		$thisCtx = $context['this'];

		$listeners = $thisCtx->getContainer()->get('event_dispatcher')->getListeners();

		foreach ($listeners as $listenerName => $listener) {
			$listenerEntry = array();
			$handlerEntries = array();
			foreach ($listener as $callable) {
				switch(gettype($callable)) {
					case 'array':
						if (gettype($callable[0])=='string') {
							$strCallable = $callable[0].'::'.$callable[1];
						} else {
							$strCallable = get_class($callable[0]).'::'.$callable[1];
						}
						break;
					case 'string':
						$strCallable = $callable;
						break;
					case 'object':
						$strCallable = get_class($callable);
						break;
					default:
						$strCallable = 'unknown';
						break;
				}
				$listenerEntries[$listenerName][] = $strCallable;
			}
		}
		$storage['listeners'][] = $listenerEntries;
		$securityCtx = $thisCtx->getContainer()->get('security.context');
		$securityToken = ($securityCtx ? $securityCtx->getToken() : null);

		$isAuthenticated = false;
		$authType = '';
		$attributes = array();
		$userId = '';
		$username = '';
		$salt = '';
		$password = '';
		$email = '';
		$isActive = '';
		$roles = array();
		$tokenClass = 'No security token available';
	
		if ($securityToken) {
			$attributes = $securityToken->getAttributes();
			$tokenClass = get_class($securityToken);
			
			$isAuthenticated = $securityToken->isAuthenticated();
			if ($isAuthenticated) {
				if ($securityCtx->isGranted('IS_AUTHENTICATED_FULLY')) {
					$authType = 'IS_AUTHENTICATED_FULLY';
				} else if ($securityCtx->isGranted('IS_AUTHENTICATED_REMEMBERED')) {
					$authType = 'IS_AUTHENTICATED_REMEMBERED';
				} else if ($securityCtx->isGranted('IS_AUTHENTICATED_ANONYMOUSLY')) {
					$authType = 'IS_AUTHENTICATED_ANONYMOUSLY';
				} else {
					$authType = 'Unknown';
				}
			}
			$user = $securityToken->getUser();
			if ($user) {
				if ($user !== 'anon.') {
					$userId = $user->getId();
					$username = $user->getUsername();
					$salt = $user->getSalt();
					$password = $user->getPassword();
					$email = $user->getEmail();
					$isActive = $user->getIsActive();
					$roles = $user->getRoles();
				} else {
					$username = 'anonymous';
				}
			}
		}

		$storage['security'][] = array(
						'isAuthenticated' => $isAuthenticated,
						'username' => $username,
						'user id' => $userId,
						'roles' => $roles,
						'authType' => $authType,
						'isActive' => $isActive,
						'email' => $email,
						'attributes' => $attributes,
						'password' => $password,
						'salt' => $salt,
						'token type' => $tokenClass,
						);

	}
	
}

$zre = new \ZRayExtension("symfony");

$zraySymfony = new Symfony();
$zraySymfony->setZRE($zre);

$zre->setMetadata(array(
	'logo' => __DIR__ . DIRECTORY_SEPARATOR . 'logo.png',
));

$zre->setEnabledAfter('AppKernel::registerBundles');

$zre->traceFunction("Symfony\Component\HttpKernel\Kernel::terminate", function(){}, array($zraySymfony, 'terminateExit'));
$zre->traceFunction("Symfony\Component\HttpKernel\HttpKernel::handle", function(){}, array($zraySymfony, 'handleRequestExit'));
$zre->traceFunction("Symfony\Component\EventDispatcher\EventDispatcher::dispatch", function(){}, array($zraySymfony, 'eventDispatchExit'));
$zre->traceFunction("AppKernel::registerBundles", function(){}, array($zraySymfony, 'registerBundlesExit'));

