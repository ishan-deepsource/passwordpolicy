<?php

namespace OxidProfessionalServices\PasswordPolicy\Component;

use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\Exception\UserException;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\Request;
use OxidEsales\EshopCommunity\Core\Field;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidProfessionalServices\PasswordPolicy\Core\PasswordPolicyConfig;
use OxidProfessionalServices\PasswordPolicy\TwoFactorAuth\PasswordPolicyTOTP;

class PasswordPolicyUserComponent extends PasswordPolicyUserComponent_parent
{
    const USER_COOKIE_SALT = 'user_cookie_salt';
    private string $step = 'checkout';

    public function createUser()
    {
        $twoFactor = (new Request)->getRequestEscapedParameter('2FA');
        $container = ContainerFactory::getInstance()->getContainer();
        $config = $container->get(PasswordPolicyConfig::class);
        $twofactorconf = $config->isTOTP();
        $paymentActionLink = parent::createUser();
        if($twofactorconf && $twoFactor && $paymentActionLink)
        {
            Registry::getUtils()->redirect(Registry::getConfig()->getShopHomeUrl() . 'cl=twofactorregister&step='. $this->step . '&paymentActionLink='. urlencode($paymentActionLink) . '&success=1');
        }
        return $paymentActionLink;


    }

    public function registerUser()
    {
        $this->step = 'registration';
        return parent::registerUser();
    }
    public function finalizeRegistration()
    {
        $container = ContainerFactory::getInstance()->getContainer();
        $TOTP = $container->get(PasswordPolicyTOTP::class);
        $OTP = (new Request())->getRequestEscapedParameter('otp');
        $secret = Registry::getSession()->getVariable('otp_secret');
        $checkOTP = $TOTP->checkOTP($secret, $OTP);
        if($checkOTP)
        {
            //finalize
            $user = $this->getUser();
            $user->oxuser__oxpstotpsecret = new Field($secret, Field::T_TEXT);
            $user->save();
            //cleans up session for next registration
            Registry::getSession()->deleteVariable('otp_secret');
            $step = (new Request())->getRequestEscapedParameter('step');
            $paymentActionLink = (new Request())->getRequestEscapedParameter('paymentActionLink');
            return 'twofactorrecovery?step=' . $step . '&paymentActionLink=' . $paymentActionLink;
        }
        Registry::getUtilsView()->addErrorToDisplay('OXPS_PASSWORDPOLICY_TOTP_ERROR_WRONGOTP');
    }

    public function getRedirectLink()
    {
        $step = (new Request())->getRequestEscapedParameter('step');
        $paymentActionLink = (new Request())->getRequestEscapedParameter('paymentActionLink');
        $redirect = urldecode($paymentActionLink);
        if($step == 'registration')
        {
            $redirect = 'register?success=1';
        }
        elseif($step == 'settings')
        {
            $redirect = 'twofactoraccount?success=1';
        }
        return $redirect;
    }

    public function finalizeLogin()
    {
        $otp = (new Request())->getRequestEscapedParameter('otp');
        $setsessioncookie = (new Request())->getRequestEscapedParameter('setsessioncookie');
        $this->setLoginStatus(USER_LOGIN_FAIL);
        try {
            $user = oxNew(User::class);
            $user->finalizeLogin($otp, $setsessioncookie);
            $this->setLoginStatus(USER_LOGIN_SUCCESS);
        }catch(UserException $ex)
        {
            return Registry::getUtilsView()->addErrorToDisplay($ex);
        }
        return $this->_afterLogin($user);
    }
}