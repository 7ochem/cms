<?php
declare(strict_types=1);

namespace craft\authentication\type;

use Craft;
use craft\authentication\base\ElevatedSessionTypeInterface;
use craft\authentication\base\Type;
use craft\authentication\base\UserConfigurableTypeInterface;
use craft\authentication\webauthn\CredentialRepository;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\records\AuthWebAuthn;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource as CredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\Server;
use yii\base\InvalidConfigException;
use yii\web\Cookie;

/**
 * This step type requires an authentication type that supports Web Authentication API.
 * This step type requires a user to be identified by a previous step.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 4.0.0
 *
 * @property-read string $inputFieldHtml
 */
class WebAuthn extends Type implements UserConfigurableTypeInterface, ElevatedSessionTypeInterface
{
    /**
     * The key for session to use for storing the WebAuthn credential options.
     */
    public const WEBAUTHN_CREDENTIAL_OPTION_KEY = 'user.webauthn.credentialOptions';

    public const WEBAUTHN_CREDENTIAL_REQUEST_OPTION_KEY = 'user.webauthn.credentialRequestOptions';
    public const WEBAUTHN_COOKIE_NAME = 'craft_webauthn';

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('app', 'Authenticate with WebAuthn');
    }

    /**
     * @inheritdoc
     */
    public static function getDescription(): string
    {
        return Craft::t('app', 'Authenticate using a Yubikey or TouchID.');
    }

    /**
     * @inheritdoc
     */
    public function getFields(): ?array
    {
        return ['credentialResponse'];
    }

    /**
     * @inheritdoc
     */
    public function authenticate(array $credentials, User $user = null): bool
    {
        if (empty($credentials['credentialResponse']) || !$user) {
            return false;
        }

        $credentialResponse = Json::encode($credentials['credentialResponse']);

        try {
            self::getWebauthnServer()->loadAndCheckAssertionResponse(
                $credentialResponse,
                Craft::$app->getSession()->get(self::WEBAUTHN_CREDENTIAL_REQUEST_OPTION_KEY),
                self::getUserEntity($user),
                Craft::$app->getRequest()->asPsr7()
            );
        } catch (\Throwable $exception) {
            Craft::$app->getErrorHandler()->logException($exception);
            return false;
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function getInputFieldHtml(): string
    {
        $server = self::getWebauthnServer();
        $userEntity = self::getUserEntity($this->state->getUser());
        $allowedCredentials = array_map(
            static fn(CredentialSource $credential) => $credential->getPublicKeyCredentialDescriptor(),
            Craft::createObject(CredentialRepository::class)->findAllForUserEntity($userEntity));

        $requestOptions = $server->generatePublicKeyCredentialRequestOptions(null, $allowedCredentials);
        Craft::$app->getSession()->set(self::WEBAUTHN_CREDENTIAL_REQUEST_OPTION_KEY, $requestOptions);

        return Craft::$app->getView()->renderTemplate('_components/authenticationsteps/WebAuthn/input', [
            'requestOptions' => Json::encode($requestOptions),
        ]);
    }

    /**
     * @inheritdoc
     */
    public static function getIsApplicable(?User $user): bool
    {
        if (!$user || !Craft::$app->getRequest()->getIsSecureConnection()) {
            return false;
        }

        $cookie = Craft::$app->getRequest()->getCookies()->get(self::WEBAUTHN_COOKIE_NAME);
        $cookieExists = $cookie !== null && $cookie->value == $user->uid;

        return $cookieExists && AuthWebAuthn::findOne(['userId' => $user->id]);
    }

    /**
     * @inheritdoc
     */
    public static function getHasUserSetup(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function getUserSetupFormHtml(User $user): string
    {
        $existingCredentials = AuthWebAuthn::findAll(['userId' => $user->id]);

        $credentials = [];

        foreach ($existingCredentials as $existingCredential) {
            $credentials[] = [
                'name' => $existingCredential->name,
                'credentialId' => $existingCredential->credentialId,
                'lastUsed' => DateTimeHelper::toDateTime($existingCredential->dateLastUsed)
            ];
        }

        $isSecureConnection = Craft::$app->getRequest()->getIsSecureConnection();
        return Craft::$app->getView()->renderTemplate('_components/authenticationsteps/WebAuthn/setup', [
            'credentialOptions' => $isSecureConnection ? Json::encode(self::getCredentialCreationOptions($user, true)) : '',
            'existingCredentials' => $credentials,
            'isSecureConnection' => $isSecureConnection
        ]);
    }

    /**
     * Return the WebAuthn server, responsible for key creation and validation.
     *
     * @return Server
     */
    public static function getWebauthnServer(): Server
    {
        return Craft::createObject(Server::class, [
            self::getRelayingPartyEntity(),
            Craft::createObject(CredentialRepository::class)
        ]);
    }

    /**
     * Get the credential creation options.
     *
     * @param User $user The user for which to get the credential creation options.
     * @param bool $createNew Whether new credential options should be created
     *
     * @return PublicKeyCredentialOptions | null
     */
    public static function getCredentialCreationOptions(User $user, bool $createNew = false): ?PublicKeyCredentialOptions
    {
        if (Craft::$app->getEdition() !== Craft::Pro) {
            return null;
        }

        $session = Craft::$app->getSession();
        $credentialOptions = $session->get(self::WEBAUTHN_CREDENTIAL_OPTION_KEY);

        if ($createNew || !$credentialOptions) {
            $userEntity = self::getUserEntity($user);

            $excludeCredentials = array_map(
                static fn(CredentialSource $credential) => $credential->getPublicKeyCredentialDescriptor(),
                Craft::createObject(CredentialRepository::class)->findAllForUserEntity($userEntity));

            $credentialOptions = Json::encode(
                self::getWebauthnServer()->generatePublicKeyCredentialCreationOptions(
                    $userEntity,
                    null,
                    $excludeCredentials,
                )
            );

            $session->set(self::WEBAUTHN_CREDENTIAL_OPTION_KEY, $credentialOptions);
        }

        return PublicKeyCredentialCreationOptions::createFromArray(Json::decodeIfJson($credentialOptions));
    }

    /**
     * Returns true if a given user has configured WebAuthn credentials.
     *
     * @param User $user
     * @return bool
     * @throws InvalidConfigException
     */
    public static function userHasCredentialsConfigured(User $user): bool {
        $userEntity = self::getUserEntity($user);
        return !empty(Craft::createObject(CredentialRepository::class)->findAllForUserEntity($userEntity));
    }

    /**
     * Return a new Public Key Credential User Entity based on the currently logged in user.
     *
     * @param User $user
     * @return PublicKeyCredentialUserEntity
     */
    public static function getUserEntity(User $user): PublicKeyCredentialUserEntity
    {
        return new PublicKeyCredentialUserEntity($user->username, $user->uid, $user->friendlyName);
    }

    /**
     * Return a new Public Key Credential Relaying Party Entity based on the current Craft installations
     *
     * @return PublicKeyCredentialRpEntity
     */
    public static function getRelayingPartyEntity(): PublicKeyCredentialRpEntity
    {
        return new PublicKeyCredentialRpEntity(Craft::$app->getSites()->getPrimarySite()->getName(), Craft::$app->getRequest()->getHostName());
    }

    /**
     * Refresh the credential cookie, if it already exists.
     *
     * @param User $user
     */
    public static function refreshCredentialCookie(User $user): void
    {
        if (Craft::$app->getRequest()->getCookies()->has(self::WEBAUTHN_COOKIE_NAME)) {
            self::setCredentialCookie($user);
        }
    }
    /**
     * Set the credential cookie for a user.
     * @param User $user
     */
    public static function setCredentialCookie(User $user): void
    {
        $cookie = new Cookie();
        $cookie->secure = true;
        $cookie->httpOnly = true;
        $cookie->name = self::WEBAUTHN_COOKIE_NAME;
        $cookie->value = $user->uid;
        $week = 60 * 60 * 24 * 7;
        $cookie->expire = time() + $week;
        $cookie->sameSite = Cookie::SAME_SITE_STRICT;

        Craft::$app->getResponse()->getCookies()->add($cookie);
    }
}
