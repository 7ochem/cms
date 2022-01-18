<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\web;

use Craft;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\web\JsonResponseFormatter;
use yii\web\Response as YiiResponse;
use yii\web\ResponseFormatterInterface;

/**
 * Control panel screen response formatter.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 4.0.0
 */
class CpScreenResponseFormatter extends Component implements ResponseFormatterInterface
{
    const FORMAT = 'cp-screen';

    /**
     * @inheritdoc
     * @throws InvalidConfigException
     */
    public function format($response)
    {
        /** @var CpScreenResponseBehavior $behavior */
        $behavior = $response->getBehavior(CpScreenResponseBehavior::NAME);

        if (!$behavior) {
            throw new InvalidConfigException('CpScreenResponseFormatter can only be used on responses with a CpScreenResponseBehavior.');
        }

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            $this->_formatJson($response, $behavior);
        } else {
            $this->_formatTemplate($response, $behavior);
        }
    }

    private function _formatJson(YiiResponse $response, CpScreenResponseBehavior $behavior): void
    {
        $namespace = StringHelper::randomString(10);
        $view = Craft::$app->getView();
        $tabs = $behavior->tabs ? $view->namespaceInputs(fn() => $view->renderTemplate('_includes/tabs', [
            'tabs' => $behavior->tabs
        ], View::TEMPLATE_MODE_CP), $namespace) : null;
        $content = $behavior->content ? $view->namespaceInputs($behavior->content, $namespace) : null;
        $sidebar = $behavior->sidebar ? $view->namespaceInputs($behavior->sidebar, $namespace) : null;

        $response->data = [
            'namespace' => $namespace,
            'title' => $behavior->title,
            'tabs' => $tabs,
            'action' => $behavior->actionParam,
            'content' => $content,
            'sidebar' => $sidebar,
            'headHtml' => $view->getHeadHtml(),
            'bodyHtml' => $view->getBodyHtml(),
            'deltaNames' => $view->getDeltaNames(),
            'initialDeltaValues' => $view->getInitialDeltaValues(),
        ];

        (new JsonResponseFormatter())->format($response);
    }

    private function _formatTemplate(YiiResponse $response, CpScreenResponseBehavior $behavior): void
    {
        $content = $behavior->content ? call_user_func($behavior->content) : '';
        if ($behavior->actionParam) {
            $content .= Html::actionInput($behavior->actionParam);
            if ($behavior->redirectParam) {
                $content .= Html::redirectInput($behavior->redirectParam);
            }
        }

        $response->attachBehavior(TemplateResponseBehavior::NAME, [
            'class' => TemplateResponseBehavior::class,
            'template' => '_layouts/cp',
            'variables' => [
                'docTitle' => $behavior->docTitle ?? strip_tags($behavior->title ?? ''),
                'title' => $behavior->title,
                'crumbs' => $behavior->crumbs,
                'tabs' => $behavior->tabs,
                'fullPageForm' => (bool)$behavior->actionParam,
                'saveShortcutRedirect' => $behavior->saveShortcutRedirect,
                'content' => $content,
                'details' => $behavior->sidebar ? call_user_func($behavior->sidebar) : null,
            ],
            'templateMode' => View::TEMPLATE_MODE_CP,
        ]);

        (new TemplateResponseFormatter())->format($response);
    }
}
