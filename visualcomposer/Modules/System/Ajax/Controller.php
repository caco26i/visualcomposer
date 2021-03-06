<?php

namespace VisualComposer\Modules\System\Ajax;

if (!defined('ABSPATH')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}

use VisualComposer\Framework\Container;
use VisualComposer\Framework\Illuminate\Support\Module;
use VisualComposer\Helpers\Logger;
use VisualComposer\Helpers\Nonce;
use VisualComposer\Helpers\Request;
use VisualComposer\Helpers\Str;
use VisualComposer\Helpers\Traits\EventsFilters;
use VisualComposer\Helpers\PostType;
use VisualComposer\Helpers\Traits\WpFiltersActions;

class Controller extends Container implements Module
{
    use EventsFilters;
    use WpFiltersActions;

    protected $scope = 'ajax';

    public function __construct()
    {
        /** @see \VisualComposer\Modules\System\Ajax\Controller::listenAjax */
        $this->addEvent(
            'vcv:inited',
            'listenAjax',
            100
        );
        /** @see \VisualComposer\Modules\System\Ajax\Controller::listenAjax */
        $this->wpAddAction(
            'vcv:boot',
            'disableAjaxErrors',
            10
        );
    }

    protected function getResponse($requestAction)
    {
        $response = vcfilter('vcv:' . $this->scope, '');
        $response = vcfilter('vcv:' . $this->scope . ':' . $requestAction, $response);

        return $response;
    }

    protected function renderResponse($response)
    {
        if (is_string($response)) {
            return $response;
        }

        return json_encode($response);
    }

    protected function disableAjaxErrors(Request $requestHelper)
    {
        if ($requestHelper->exists(VCV_AJAX_REQUEST)) {
            if (!vcvenv('VCV_DEBUG')) {
                ini_set('display_errors', 'Off');
                ini_set('error_reporting', 0);
                error_reporting(0);
            }
        }
    }

    protected function listenAjax(Request $requestHelper)
    {
        if ($requestHelper->exists(VCV_AJAX_REQUEST)) {
            $this->setGlobals();
            /** @see \VisualComposer\Modules\System\Ajax\Controller::parseRequest */
            $rawResponse = $this->call('parseRequest');
            $output = $this->renderResponse($rawResponse);
            $this->output($output, $rawResponse);
        }
    }

    protected function setGlobals()
    {
        if (!defined('VCV_AJAX_REQUEST_CALL')) {
            define('VCV_AJAX_REQUEST_CALL', true);
        }
        if (!defined('DOING_AJAX')) {
            define('DOING_AJAX', true);
        }
    }

    /**
     * @param \VisualComposer\Helpers\Request $requestHelper
     * @param \VisualComposer\Helpers\PostType $postTypeHelper
     */
    protected function setSource(Request $requestHelper, PostType $postTypeHelper)
    {
        if ($requestHelper->exists('vcv-source-id')) {
            $postTypeHelper->setupPost((int)$requestHelper->input('vcv-source-id'));
        }
    }

    protected function output($response, $rawResponse)
    {
        if (vcIsBadResponse($rawResponse)) {
            $loggerHelper = vchelper('Logger');
            $messages = [];
            if (is_wp_error($rawResponse)) {
                /** @var $rawResponse \WP_Error */
                $messages[] = implode('. ', $rawResponse->get_error_messages());
            } elseif (is_array($rawResponse)) {
                if (isset($rawResponse['body'])) {
                    $messages[] = $rawResponse['body'];
                }
                if (isset($rawResponse['message'])) {
                    $messages[] = is_array($rawResponse['message']) ? implode('. ', $rawResponse['message'])
                        : $rawResponse['message'];
                }
            }
            if ($loggerHelper->all()) {
                $messages[] = $loggerHelper->all();
            }
            if (count($messages) > 0) {
                echo json_encode(
                    [
                        'status' => false,
                        'response' => $rawResponse,
                        'message' => implode('. ', $messages),
                        'details' => $loggerHelper->details(),
                    ]
                );
                vcvdie(); // DO NOT USE WP_DIE because it can be overwritten by 3rd and cause plugin issues.
            } else {
                echo json_encode(
                    [
                        'status' => false,
                        'response' => $rawResponse,
                        'details' => $loggerHelper->details(),
                    ]
                );
                vcvdie(); // DO NOT USE WP_DIE because it can be overwritten by 3rd and cause plugin issues.
            }
        }

        vcvdie($response); // DO NOT USE WP_DIE because it can be overwritten by 3rd and cause plugin issues.
    }

    protected function parseRequest(Request $requestHelper, Logger $loggerHelper)
    {
        if ($requestHelper->exists('vcv-zip')) {
            $zip = $requestHelper->input('vcv-zip');
            $basedecoded = base64_decode($zip);
            $newAllJson = zlib_decode($basedecoded);
            $newArgs = json_decode($newAllJson, true);
            $all = $requestHelper->all();
            $new = array_merge($all, $newArgs);
            $requestHelper->setData($new);
        }

        // Require an action parameter.
        if (!$requestHelper->exists('vcv-action')) {
            $loggerHelper->log(
                'Action doesn`t set #10074',
                [
                    'request' => $requestHelper->all(),
                ]
            );

            return false;
        }
        $requestAction = $requestHelper->input('vcv-action');
        /** @see \VisualComposer\Modules\System\Ajax\Controller::validateNonce */
        $validateNonce = $this->call('validateNonce', [$requestAction]);
        if ($validateNonce) {
            /** @see \VisualComposer\Modules\System\Ajax\Controller::setSource */
            $this->call('setSource');

            /** @see \VisualComposer\Modules\System\Ajax\Controller::getResponse */
            return $this->call('getResponse', [$requestAction]);
        } else {
            $loggerHelper->log(
                'Nonce not validated #10075',
                [
                    'request' => $requestHelper->all(),
                ]
            );
        }

        return false;
    }

    protected function validateNonce($requestAction, Request $requestHelper, Str $strHelper, Nonce $nonceHelper)
    {
        if ($strHelper->contains($requestAction, ':nonce')) {
            return $nonceHelper->verifyUser(
                $requestHelper->input('vcv-nonce')
            );
        } elseif ($strHelper->contains($requestAction, ':adminNonce')) {
            return $nonceHelper->verifyAdmin(
                $requestHelper->input('vcv-nonce')
            );
        }

        return true;
    }
}
