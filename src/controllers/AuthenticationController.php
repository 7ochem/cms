<?php
declare(strict_types=1);
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\controllers;

use Craft;
use craft\authentication\base\Type;
use craft\authentication\Chain;
use craft\authentication\type\mfa\AuthenticatorCode;
use craft\authentication\type\mfa\WebAuthn;
use craft\authentication\webauthn\CredentialRepository;
use craft\elements\User;
use craft\helpers\Authentication as AuthenticationHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\services\Authentication;
use craft\web\Controller;
use Webauthn\Server;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * The AuthenticationController class is a controller that handles various authentication related tasks.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 4.0.0
 */
class AuthenticationController extends Controller
{
    protected $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE;

    /** @var
     * string The session variable name to use to store whether user wants to be remembered.
     */
    private const REMEMBER_ME = 'authChain.rememberMe';

    /** @var
     * string The session variable name to use the entered user name.
     */
    private const AUTH_USER_NAME = 'authChain.userName';

    /**
     * Start a new authentication chain.
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionStartAuthentication(): Response
    {
        $this->requireAcceptsJson();
        $request = Craft::$app->getRequest();
        $username = $request->getBodyParam('username');

        if (empty($username)) {
            return $this->asJson(['loginFormHtml' => Craft::$app->getView()->renderTemplate('_special/login/login_form')]);
        }

        $user = $this->_getUser($username);

        if (!$user) {
            return $this->asErrorJson(Craft::t('app', 'Invalid username or email.'));
        }

        Craft::$app->getSession()->set(self::AUTH_USER_NAME, $username);

        $userComponent = Craft::$app->getUser();
        $userComponent->sendUsernameCookie($user);

        $authentication = Craft::$app->getAuthentication();
        $authentication->invalidateAllAuthenticationStates();
        $chain = $authentication->getCpAuthenticationChain($user);

        $nextStep = $chain->getNextAuthenticationStep();
        $nextStep->prepareForAuthentication($user);

        $chain->persistChainState();

        $session = Craft::$app->getSession();

        return $this->asJson([
            'loginFormHtml' => Craft::$app->getView()->renderTemplate('_special/login/login_form', compact('user')),
            'footHtml' => Craft::$app->getView()->getBodyHtml(),
            'stepType' => $nextStep->getStepType(),
            'message' => $session->getNotice(),
            'error' => $session->getError(),
        ]);
    }
    /**
     * Perform an authentication step.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws InvalidConfigException
     * @throws \Exception
     */
    public function actionPerformAuthentication(): Response
    {
        $this->requireAcceptsJson();
        $scenario = Authentication::CP_AUTHENTICATION_CHAIN;
        $request = Craft::$app->getRequest();
        $stepType = $request->getBodyParam('stepType', '');
        $username = Craft::$app->getSession()->get(self::AUTH_USER_NAME) ?? Craft::$app->getUser()->getRememberedUsername();
        $user = null;

        if ($username) {
            $user = $this->_getUser($username);
        }

        if (!$user) {
            throw new BadRequestHttpException('Unable to determine user');
        }

        $chain = Craft::$app->getAuthentication()->getAuthenticationChain($scenario, $user);
        $switch = !empty($request->getBodyParam('switch'));

        if ($switch) {
            return $this->_switchStep($chain, $stepType);
        }

        try {
            $step = $chain->getNextAuthenticationStep($stepType);
        } catch (InvalidConfigException $exception) {
            throw new BadRequestHttpException('Unable to authenticate', 0, $exception);
        }

        $session = Craft::$app->getSession();
        $success = false;

        if ($step !== null) {
            $data = [];

            if ($fields = $step->getFields()) {
                foreach ($fields as $fieldName) {
                    if ($value = $request->getBodyParam($fieldName)) {
                        $data[$fieldName] = $value;
                    }
                }
            }

            $success = $chain->performAuthenticationStep($stepType, $data);

            if ($success && $request->getBodyParam('rememberMe')) {
                $session->set(self::REMEMBER_ME, true);
            }
        }

        if ($chain->getIsComplete()) {
            $generalConfig = Craft::$app->getConfig()->getGeneral();

            if ($session->get(self::REMEMBER_ME) && $generalConfig->rememberedUserSessionDuration !== 0) {
                $duration = $generalConfig->rememberedUserSessionDuration;
            } else {
                $duration = $generalConfig->userSessionDuration;
            }

            Craft::$app->getUser()->login($chain->getAuthenticatedUser(), $duration);
            $session->remove(self::REMEMBER_ME);

            $userSession = Craft::$app->getUser();
            $returnUrl = $userSession->getReturnUrl();
            $userSession->removeReturnUrl();

            return $this->asJson([
                'success' => true,
                'returnUrl' => $returnUrl
            ]);
        }

        $output = [
            'message' => $session->getNotice(),
            'error' => $session->getError(),
        ];

        /** @var Type $step */
        $step = $chain->getNextAuthenticationStep();

        if ($success || $chain->getDidSwitchBranches()) {
            if ($success) {
                $output['stepComplete'] = true;
            }

            $output['stepType'] = $step->getStepType();
            $output['html'] = $step->getInputFieldHtml();
            $output['footHtml'] = Craft::$app->getView()->getBodyHtml();
        }

        $output['alternatives'] = $chain->getAlternativeSteps(get_class($step));

        return $this->asJson($output);
    }

    /**
     * Detach web authn credentials
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws \Throwable
     */
    public function actionDetachWebAuthnCredentials(): Response
    {
        $this->requireAcceptsJson();
        $this->requireLogin();

        // TODO require elevated session once admintable allows support for it
        //$this->requireElevatedSession();
        $userSession = Craft::$app->getUser();
        $currentUser = $userSession->getIdentity();

        $credentialId = Craft::$app->getRequest()->getRequiredBodyParam('id');

        return $this->asJson(['success' => (new CredentialRepository())->deleteCredentialSourceForUser($currentUser, $credentialId)]);
    }

    /**
     * Attach WebAuthn credentials.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionAttachWebAuthnCredentials(): Response
    {
        $this->requireAcceptsJson();
        $this->requireLogin();
        $this->requireElevatedSession();

        $request = Craft::$app->getRequest();
        $payload = $request->getRequiredBodyParam('credentials');
        $credentialName = $request->getBodyParam('credentialName', '');

        $userSession = Craft::$app->getUser();
        $currentUser = $userSession->getIdentity();

        $output = [];

        try {
            $credentialRepository = new CredentialRepository();

            $server = new Server(
                WebAuthn::getRelayingPartyEntity(),
                $credentialRepository
            );

            $options = WebAuthn::getCredentialCreationOptions($currentUser);
            $credentials = $server->loadAndCheckAttestationResponse(Json::encode($payload), $options, $request->asPsr7());
            $credentialRepository->saveNamedCredentialSource($credentials, $credentialName);

            $step = new WebAuthn();
            $output['html'] = $step->getUserSetupFormHtml($currentUser);
            $output['footHtml'] = Craft::$app->getView()->getBodyHtml();
        } catch (\Throwable $exception) {
            Craft::$app->getErrorHandler()->logException($exception);
            $output['error'] = Craft::t('app', 'Something went wrong when attempting to attach credentials.');
        }

        return $this->asJson($output);
    }

    /**
     * Update authenticator settings.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws \PragmaRX\Google2FA\Exceptions\Google2FAException
     * @throws \craft\errors\MissingComponentException
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionUpdateAuthenticatorSettings(): Response
    {
        $this->requireAcceptsJson();
        $this->requireLogin();
        $this->requireElevatedSession();

        $userSession = Craft::$app->getUser();
        $currentUser = $userSession->getIdentity();

        $request = Craft::$app->getRequest();
        $session = Craft::$app->getSession();
        $output = [];
        $message = '';

        if (Craft::$app->getEdition() === Craft::Pro) {
            $code1 = $request->getBodyParam('verification-code-1');
            $code2 = $request->getBodyParam('verification-code-2');
            $detach = $request->getBodyParam('detach');

            if (!empty($code1) || !empty($code2)) {
                $authenticator = AuthenticationHelper::getCodeAuthenticator();

                $authenticator->setWindow(4);
                $existingSecret = $session->get(AuthenticatorCode::AUTHENTICATOR_SECRET_SESSION_KEY);
                $firstTimestamp = $authenticator->verifyKeyNewer($existingSecret, $code1, 100);

                if ($firstTimestamp) {
                    // Ensure sequence of two codes
                    $secondTimestamp = $authenticator->verifyKeyNewer($existingSecret, $code2, $firstTimestamp);

                    if ($secondTimestamp) {
                        $currentUser->saveAuthenticator($existingSecret, $secondTimestamp);
                        $session->remove(AuthenticatorCode::AUTHENTICATOR_SECRET_SESSION_KEY);
                        $message = Craft::t('app', 'Successfully attached the authenticator.');
                    }
                } else {
                    $message = Craft::t('app', 'Failed to verify two consecutive codes.');
                }
            } else if (!empty($detach)) {

                if ($detach === 'detach') {
                    $currentUser->removeAuthenticator();
                    $message = Craft::t('app', 'Successfully detached the authenticator.');
                } else {
                    $message = Craft::t('app', 'Failed to detach the authenticator.');
                }
            }

        }

        if ($message) {
            $output['message'] = $message;
        }

        $step = new AuthenticatorCode();
        $output['html'] = $step->getUserSetupFormHtml($currentUser);

        return $this->asJson($output);
    }

    /**
     * Switch to an alternative step on the auth chain.
     *
     * @param Chain $authenticationChain
     * @param string $stepType
     * @return Response
     * @throws InvalidConfigException
     */
    private function _switchStep(Chain $authenticationChain, string $stepType): Response
    {
        $step = $authenticationChain->switchStep($stepType);
        $session = Craft::$app->getSession();

        $output = [
            'html' => $step->getInputFieldHtml(),
            'footHtml' => Craft::$app->getView()->getBodyHtml(),
            'alternatives' => $authenticationChain->getAlternativeSteps(get_class($step)),
            'stepType' => $step->getStepType(),
            'message' => $session->getNotice(),
            'error' => $session->getError(),

        ];

        return $this->asJson($output);
    }

    /**
     * Return a user by username, faking it, if required by config.
     *
     * @param string $username
     * @return User|null
     */
    private function _getUser(string $username): ?User {
        $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($username);

        if (!$user && Craft::$app->getConfig()->getGeneral()->preventUserEnumeration) {
            $user = AuthenticationHelper::getFakeUser($username);
        }

        return $user;
    }
}
