<?php

namespace bdk\TinyFrame;

use bdk\Net;
use bdk\PubSub\Event;
use bdk\PubSub\Manager;
use bdk\PubSub\SubscriberInterface;

/**
 * Page Alerts
 */
class Alerts implements SubscriberInterface
{

	protected $debug;
	protected $alerts = array();
	protected $alertsOutput = array();

	/**
	 * Constructor
	 *
	 * @param Manager $eventManager Event Manager
	 */
	public function __construct(Manager $eventManager)
	{
		$this->eventManager = $eventManager;
	}

	/**
	 * Add an alert
	 *
	 * @param string  $alert       alert
	 * @param string  $class       [danger] success, info, warning,
	 * @param boolean $dismissible [true]
	 *
	 * @return void
	 */
	public function add($alert, $class = 'danger', $dismissible = true)
	{
		$alertDefault = array(
			'alert' => '',
			'class' => $class,
			'dismissible' => $dismissible,
		);
		if (!\is_array($alert)) {
			$alert = array(
				'alert' => $alert,
			);
		} else {
			// may have been passed as non-assoc array
			foreach (array('alert','class','dismissible') as $i => $key) {
				if (isset($alert[$i])) {
					$alert[$key] = $alert[$i];
					unset($alert[$i]);
				}
			}
		}
		if (\is_array($class)) {
			// options array passed as second param
			$alert = \array_merge($alert, $class);
		}
		$alert = \array_merge($alertDefault, $alert);
		$this->alerts[] = $alert;
	}

	/**
	 * Build an alert
	 *
	 * @param array $alert 'alert','class','dismissable'
	 *
	 * @return string
	 */
	public function build($alert = array())
	{
		$str = '';
		$alert = \array_merge(array(
			'alert'			=> '',
			'dismissible'	=> true,
			'class'			=> 'danger',		// success info warning danger
			'output'		=> '',
		), $alert);
		$alert['class'] = 'alert-'.$alert['class'];
		$event = $this->eventManager->publish('alerts.build', null, $alert);
		$alert = $event->getValues();
		if (!empty($alert['output'])) {
			return $alert['output'];
		}
		if ($alert['dismissible']) {
			$str .= '<div class="alert alert-dismissible '.$alert['class'].'" role="alert">'
				.'<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>'
				.$alert['alert']
				.'</div>';
		} else {
			$str .= '<div class="alert '.$alert['class'].'" role="alert">'.$alert['alert'].'</div>';
		}
		return $str;
	}

	/**
	 * Clear queued alerts
	 *
	 * @return void
	 */
	public function clear()
	{
		$this->alerts = array();
	}

	/**
	 * How many alerts are queued?
	 *
	 * @return integer
	 */
	public function count()
	{
		return \count($this->alerts);
	}

	/**
	 * get all alerts
	 *
	 * @return array
	 */
	public function getAll()
	{
		return $this->alerts;
	}

	/**
	 * Generate alerts html
	 *
	 * @return string
	 */
	public function getAlerts()
	{
		$str = '';
		$this->alertsOutput = array();
		foreach ($this->alerts as $alert) {
			$this->alertsOutput[] = $alert;
			$str .= $this->build($alert);
		}
		if (!empty($str)) {
			// wrap the alerts?
			/*
			if ($tmplFramework == 'jQmobile') {
				$swatch = !isset($GLOBALS['page']['jqm_ver']) || version_compare($GLOBALS['page']['jqm_ver'], '1.4.0', '>=')
					? 'ui-bar-y'
					: 'ui-bar-e';
				$str = '<div class="ui-header ui-bar '.$swatch.' alerts">'.$str.'</div>';
			} elseif ($tmplFramework == 'other') {
				$str = '<div id="alerts">'.$str.'</div>';
			}
			*/
			$str = $this->eventManager->publish('alerts.getAlerts', null, array(
				'alertsOutput' => $this->alertsOutput,
				'output' => $str,
			))['output'];
		}
		$this->alerts = array();
		return $str;
	}

    /**
     * get subscribed events
     *
     * @return array
     */
    public function getSubscriptions()
    {
    	return array(
			'page.output' => 'onOutput',
    	);
    }

	/**
	 * Add alerts to output if not already added
	 *
	 * @param Event $event event object
	 *
	 * @return void
	 */
	public function onOutput(Event $event)
	{
		$page = $event->getSubject();
        if (Net::getHeaderValue('Content-Type') == 'text/html') {
            if (!empty($this->alerts)) {
                if (!$page->content->isKeyOutput('alerts')) {
                    // alerts not output... adding alerts
                    $alerts = $this->getAlerts();
                    $page->content->update('body', $alerts, 'top');
                } else {
                    // alerts already output -> replace
                    $this->alerts = \array_merge($this->alertsOutput, $this->alerts);
                    $page->content->update('alerts', $this->getAlerts(), 'replace');
                }
            }
        }
	}
}
