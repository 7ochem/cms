<?php
declare(strict_types=1);
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\services;

use Craft;
use craft\authentication\base\ElevatedSessionTypeInterface;
use craft\authentication\base\MfaTypeInterface;
use craft\authentication\base\UserConfigurableTypeInterface;
use craft\authentication\State;
use craft\authentication\type\IpAddress;
use craft\authentication\type\AuthenticatorCode;
use craft\authentication\type\EmailCode;
use craft\authentication\type\Password;
use craft\authentication\type\WebAuthn;
use craft\elements\User;
use craft\errors\AuthenticationException;
use yii\base\Component;
use yii\base\InvalidConfigException;

class Authentication extends Component
{
    public const AUTHENTICATION_STATE_KEY = 'craft.authentication.state';

    /**
     * When state is created, uniqid => state path is generated and stored inside state.
     * State can return alternative paths - siblings to current location or any of the parents
     * to further a state
     */


    /**
     * A list of all the authentication step types.
     *
     * @var array|null
     */
    private ?array $_stepTypes = null;

    private ?State $_state = null;

    public function getAuthFlow(?User $user = null): array
    {
        // TODO event here
        $flow = [];

        if ($user && $this->isWebAuthnAvailable($user)) {
            $flow[] = [
                'type' => WebAuthn::class,
            ];
        }

        $authentication = [
            'type' => Password::class
        ];

        if ($user && $this->isMfaRequired($user)) {
            $availableTypes = $this->getAvailableMfaTypes($user);

            if (empty($availableTypes)) {
                throw new AuthenticationException('Unable to find a supported MFA authentication step type, but it is required.');
            }

            $authentication['then'] = array_map(static fn ($type) => ['type' => $type], $availableTypes);
        }

        $flow[] = $authentication;

        // TODO event here
        return $flow;
    }

    /**
     * Returns true if WebAuthn credentials are available for a given user.
     *
     * @param User $user
     * @return bool
     * @throws InvalidConfigException
     */
    public function isWebAuthnAvailable(User $user): bool
    {
        $config = true;
        return $config && Craft::$app->getRequest()->getIsSecureConnection() && WebAuthn::userHasCredentialsConfigured($user);
    }

    /**
     * Returns true if MFA is required for a given user.
     *
     * @param User $user
     * @return bool
     */
    public function isMfaRequired(User $user): bool
    {
        // user forced by config || $user opted in.
        $userOption = true;

        return $userOption;
    }

    /**
     * Return a list of all the multi-factor authentication step types.
     *
     * @return string[]
     */
    public function getMfaTypes(): array
    {
        return array_filter($this->getAllStepTypes(), static fn ($type) => is_subclass_of($type, MfaTypeInterface::class));
    }

    /**
     * Return a list of all the authentication step types that must be configured by the user.
     *
     * @return string[]
     */
    public function getUserConfigurableTypes(): array
    {
        return array_filter($this->getAllStepTypes(), static fn ($type) => is_subclass_of($type, UserConfigurableTypeInterface::class));
    }

    /**
     * Return a list of all the authentication step types that can be used when elevating a session.
     *
     * @return array
     */
    public function getElevatedSessionTypes(): array
    {
        return array_filter($this->getAllStepTypes(), static fn ($type) => is_subclass_of($type, ElevatedSessionTypeInterface::class));
    }

    /**
     * @return string[]
     */
    public function getAllStepTypes(): array
    {
        if (!is_null($this->_stepTypes)) {
            return $this->_stepTypes;
        }
        $types = [
            WebAuthn::class,
            Password::class,
            AuthenticatorCode::class,
            EmailCode::class,
            IpAddress::class,
        ];

        // TODO event here.

        return $this->_stepTypes = $types;
    }

    /**
     * Return an array of all the available mfa types for a given user.
     *
     * @param User $user
     * @return array
     */
    public function getAvailableMfaTypes(User $user): array
    {
        $availableTypes = [];

        foreach ($this->getMfaTypes() as $type) {
            /** @var MfaTypeInterface $type */
            if ($type::isAvailableForUser($user)) {
                $availableTypes[] = $type;
            }
        }

        return $availableTypes;
    }

    /**
     * Get the current authentication state for a scenario.
     *
     * @param string $scenario
     * @return State|null
     */
    public function getAuthState(): ?State
    {
        if ($this->_state) {
            return $this->_state;
        }

        $session = Craft::$app->getSession();
        $serializedState = $session->get(self::AUTHENTICATION_STATE_KEY);

        if ($serializedState) {
            $this->_state = unserialize($serializedState, [State::class, User::class]);
        } else {
            $this->_state = Craft::createObject(State::class, [
                'authFlow' => $this->getAuthFlow()
            ]);
        }

        return $this->_state;
    }

    /**
     * Store an authentication state in the session.
     *
     * @param State $state
     */
    public function persistAuthenticationState(State $state): void
    {
        $this->_state = $state;
        $session = Craft::$app->getSession();
        $session->set(self::AUTHENTICATION_STATE_KEY, serialize($state));
    }

    /**
     * Invalidate all authentication states for the session.
     */
    public function invalidateAuthenticationState(): void
    {
        $this->_state = null;
        Craft::$app->getSession()->remove(self::AUTHENTICATION_STATE_KEY);
    }
}
