<?php

namespace ZRay;

use Symfony\Component\HttpKernel\DataCollector;
use Symfony\Component\DependencyInjection;
use Symfony\Component\HttpKernel\Profiler;
use Symfony\Component\Form\Extension\DataCollector\Proxy\ResolvedTypeFactoryDataCollectorProxy;
use Symfony\Component\Form\FormRegistry;
use Symfony\Component\Form\Extension\DataCollector\FormDataExtractor;

class Symfony {
	/**
	 * @var \Symfony\Component\HttpKernel\Kernel
	 */
	private $kernel;

	public function bootExit($context, &$storage) {
		/// kick off the profiler
		$this->kernel = $context['this']; /* @var $kernel \Symfony\Component\HttpKernel\Kernel */

		$container = $this->kernel->getContainer();
		
		/// override event dispatcher with debug.event_dispatcher
		$events = new \Symfony\Component\HttpKernel\Debug\TraceableEventDispatcher(
				$container->get('event_dispatcher'),
				new \Symfony\Component\Stopwatch\Stopwatch(),
				null); /// add logger interface!
		$container->set('event_dispatcher', $events);
		
		$profiler = new Profiler\Profiler(new Profiler\FileProfilerStorage('file:/var/www/symf/app/cache/dev/profiler', '', '', 86400));
		$profiler->enable();

		$container->set('profiler', $profiler);
		/// end setup
		
		$request = new DataCollector\RequestDataCollector();
		$profiler->add($request);
		$container->set('data_collector.request', $request);
		/// collect controller information
		$events->addListenerService('kernel.controller', array(0 => 'data_collector.request', 1 => 'onKernelController'), 0);

		$config = new DataCollector\ConfigDataCollector();
		if ($container->has('kernel')) {
			$config->setKernel($this->kernel);
		}
		$profiler->add($config);
		
		$formCollector = new \Symfony\Component\Form\Extension\DataCollector\FormDataCollector(new FormDataExtractor());
		$formCollectorProxy = new ResolvedTypeFactoryDataCollectorProxy($container->get('form.resolved_type_factory'), $formCollector);
		$container->set('form.resolved_type_factory', $formCollectorProxy);
		$profiler->add($formCollector);
		
		/// form collector has to be injected into the form registry as an extension
		$formRegistry = $container->get('form.registry'); /* @var $formRegistry FormRegistry */
		
		$container->set('form.type_extension.form.data_collector', new \Symfony\Component\Form\Extension\DataCollector\Type\DataCollectorTypeExtension($formCollector));
		
		$extensions = $formRegistry->getExtensions();
		$dependencyInjection = $extensions[0]; /* @var $oldDependencyInjection \Symfony\Component\Form\Extension\DependencyInjection\DependencyInjectionExtension */
		$reflection = new \ReflectionProperty('Symfony\Component\Form\Extension\DependencyInjection\DependencyInjectionExtension', 'typeExtensionServiceIds');
		$reflection->setAccessible(true);
		$extensionServices = $reflection->getValue($dependencyInjection);
		
		if ( ! isset($extensionServices['form'])) {
			$extensionServices['form'] = array();
		}
		
		if ( ! in_array('form.type_extension.form.data_collector', $extensionServices['form'])) {
			array_push($extensionServices['form'], 'form.type_extension.form.data_collector');
			$reflection->setValue($dependencyInjection, $extensionServices);
		}
		//// end form collector integration as form registry extension
		
		$profiler->add(new \Symfony\Bundle\SecurityBundle\DataCollector\SecurityDataCollector($container->get('security.context', DependencyInjection\ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE)));
		
		$profiler->add(new DataCollector\EventDataCollector($events));

		
		/// register request information listener
		$listener = new \Symfony\Component\HttpKernel\EventListener\ProfilerListener($profiler, NULL, false, false, $container->get('request_stack'));
		$container->set('profiler_listener', $listener);

		$events->addSubscriberService('profiler_listener', $listener);
		
	}
	
	/**
	 * Catch collector data before it gets wiped out
	 * 
	 * @param array $context
	 */
	public function eventDataCollector_lateCollectExit($context, &$storage) {

		$profiler = $this->kernel->getContainer()->get('profiler'); /* @var $profiler Profiler\Profiler */
		$events = $profiler->get('events'); /* @var $router DataCollector\EventDataCollector */
		
		$ref = new \ReflectionObject($events);
		$prop = $ref->getProperty('data');
		$prop->setAccessible(true);
		$listeners = $prop->getValue($context['this']);
		
		$storage['events'] = array();
		$this->storeEventListeners($listeners['called_listeners'], $storage['events'], true);
		$this->storeEventListeners($listeners['not_called_listeners'], $storage['events'], false);
	}
	
	public function terminateExit($context, &$storage){
		$profiler = $this->kernel->getContainer()->get('profiler'); /* @var $profiler Profiler\Profiler */
		$request = $profiler->get('request'); /* @var $router DataCollector\RequestDataCollector */
		
		if (is_null($request->getController())) {
			/// work around the double-terminate weirdness in 'dev' environment
			return ;
		}
		
		$storage['request'] = array();
		$this->storeRequest($request, $storage['request']);
		
		$config = $profiler->get('config'); /* @var $router DataCollector\ConfigDataCollector */
		$ref = new \ReflectionObject($config);
		$prop = $ref->getProperty('data');
		$prop->setAccessible(true);
		$config = $prop->getValue($config);
		$bundles = $config['bundles'];
		unset($config['bundles']);
		
		foreach ($config as $key => $entry) {
			$storage['config'][] = array('key' => $key, 'value' => $entry);
		}
		
		$storage['bundles'] = array();
		$this->storeBundles($bundles, $storage['bundles']);
		
		$forms = $profiler->get('form'); /* @var $router \Symfony\Component\Form\Extension\DataCollector\FormDataCollector */
		$storage['forms'] = array_values(current($forms->getData()));
		
		$security = $profiler->get('security'); /* @var $router DataCollector\SecurityDataCollector */
		$ref = new \ReflectionObject($security);
		$prop = $ref->getProperty('data');
		$prop->setAccessible(true);
		$storage['security'] = $prop->getValue($security);
	}
	
	/**
	 * @param DataCollector\RequestDataCollector $request
	 * @param array $storage
	 */
	private function storeRequest($request, &$storage) {
		$storage = $request->getController() + array('locale' => $request->getLocale(), 'route' => array('name' => $request->getRoute(), 'params' => $request->getRouteParams()));
	}
	
	/**
	 * @param array $bundles
	 * @param array $storage
	 */
	private function storeBundles($bundles, &$storage) {
		foreach ($bundles as $name => $path) {
			$storage[] = array('name' => $name, 'path' => $path);
		}
	}
	
	/**
	 * @param array $listeners
	 * @param array $storage
	 * @param boolean $called
	 */
	private function storeEventListeners($listeners, &$storage, $called) {
		foreach($listeners as $id => $listener) {
			$storage[] = array(
					'called' => $called,
					'id' => $id
			) + $listener;
		}
	}
}

$bootToTerminate = new Symfony();

// Allocate ZRayExtension for namespace "MyExtension"
$zre = new \ZRayExtension("symfony");
// Trace all functions that starts with 'm'
$zre->traceFunction("Symfony\Component\HttpKernel\Kernel::boot", function(){}, array($bootToTerminate, 'bootExit'));

$zre->traceFunction("Symfony\Component\HttpKernel\Kernel::terminate", function(){}, array($bootToTerminate, 'terminateExit'));

$zre->traceFunction("Symfony\Component\HttpKernel\DataCollector\EventDataCollector::lateCollect", function(){}, array($bootToTerminate, 'eventDataCollector_lateCollectExit'));
