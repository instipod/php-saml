<?php

/**
 * SAML 2 Authentication Request
 *
 */
class OneLogin_Saml2_AuthnRequest
{

    /**
     * Object that represents the setting info
     * @var OneLogin_Saml2_Settings
     */
    protected $_settings;

    /**
     * SAML AuthNRequest string
     * @var string
     */
    private $_authnRequest;

    /**
     * SAML AuthNRequest ID.
     * @var string
     */
    private $_id;

    /**
     * Constructs the AuthnRequest object.
     *
     * @param OneLogin_Saml2_Settings $settings Settings
     * @param bool   $forceAuthn When true the AuthNReuqest will set the ForceAuthn='true'
     * @param bool   $isPassive When true the AuthNReuqest will set the Ispassive='true'
     * @param bool   $setNameIdPolicy When true the AuthNReuqest will set a nameIdPolicy
     * @param string $nameIdValueReq Indicates to the IdP the subject that should be authenticated
     */
    public function __construct(OneLogin_Saml2_Settings $settings, $forceAuthn = false, $isPassive = false, $setNameIdPolicy = true, $nameIdValueReq = null)
    {
        $this->_settings = $settings;

        $spData = $this->_settings->getSPData();
        $security = $this->_settings->getSecurityData();

        $id = OneLogin_Saml2_Utils::generateUniqueID();
        $issueInstant = OneLogin_Saml2_Utils::parseTime2SAML(time());

        $subjectStr = "";
        if (isset($nameIdValueReq)) {
            $subjectStr = <<<SUBJECT

    <saml:Subject>
        <saml:NameID Format="{$spData['NameIDFormat']}">{$nameIdValueReq}</saml:NameID>
        <saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"></saml:SubjectConfirmation>
    </saml:Subject>
SUBJECT;
        }

        $nameIdPolicyStr = '';
        if ($setNameIdPolicy) {
            $nameIDPolicyFormat = $spData['NameIDFormat'];
            if (isset($security['wantNameIdEncrypted']) && $security['wantNameIdEncrypted']) {
                $nameIDPolicyFormat = OneLogin_Saml2_Constants::NAMEID_ENCRYPTED;
            }

            $nameIdPolicyStr = <<<NAMEIDPOLICY

    <samlp:NameIDPolicy
        Format="{$nameIDPolicyFormat}"
        AllowCreate="true" />
NAMEIDPOLICY;
        }


        $providerNameStr = '';
        $organizationData = $settings->getOrganization();
        if (!empty($organizationData)) {
            $langs = array_keys($organizationData);
            if (in_array('en-US', $langs)) {
                $lang = 'en-US';
            } else {
                $lang = $langs[0];
            }
            if (isset($organizationData[$lang]['displayname']) && !empty($organizationData[$lang]['displayname'])) {
                $providerNameStr = <<<PROVIDERNAME
    ProviderName="{$organizationData[$lang]['displayname']}"
PROVIDERNAME;
            }
        }

        $forceAuthnStr = '';
        if ($forceAuthn) {
            $forceAuthnStr = <<<FORCEAUTHN

    ForceAuthn="true"
FORCEAUTHN;
        }

        $isPassiveStr = '';
        if ($isPassive) {
            $isPassiveStr = <<<ISPASSIVE

    IsPassive="true"
ISPASSIVE;
        }

        $requestedAuthnStr = '';
        if (isset($security['requestedAuthnContext']) && $security['requestedAuthnContext'] !== false) {
            $authnComparison = 'exact';
            if (isset($security['requestedAuthnContextComparison'])) {
                $authnComparison = $security['requestedAuthnContextComparison'];
            }

            $authnComparisonAttr = '';
            if (!empty($authnComparison)) {
                $authnComparisonAttr = sprintf('Comparison="%s"', $authnComparison);
            }

            if ($security['requestedAuthnContext'] === true) {
                $requestedAuthnStr = <<<REQUESTEDAUTHN

    <samlp:RequestedAuthnContext $authnComparisonAttr>
        <saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</saml:AuthnContextClassRef>
    </samlp:RequestedAuthnContext>
REQUESTEDAUTHN;
            } else {
                $requestedAuthnStr .= "    <samlp:RequestedAuthnContext $authnComparisonAttr>\n";
                foreach ($security['requestedAuthnContext'] as $contextValue) {
                    $requestedAuthnStr .= "        <saml:AuthnContextClassRef>".$contextValue."</saml:AuthnContextClassRef>\n";
                }
                $requestedAuthnStr .= '    </samlp:RequestedAuthnContext>';
            }
        }

        $spEntityId = htmlspecialchars($spData['entityId'], ENT_QUOTES);
        $acsUrl = htmlspecialchars($spData['assertionConsumerService']['url'], ENT_QUOTES);
        $destination = $this->_settings->getIdPSSOUrl();
        $request = <<<AUTHNREQUEST
<samlp:AuthnRequest
    xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"
    xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"
    ID="$id"
    Version="2.0"
{$providerNameStr}{$forceAuthnStr}{$isPassiveStr}
    IssueInstant="$issueInstant"
    Destination="{$destination}"
    ProtocolBinding="{$spData['assertionConsumerService']['binding']}"
    AssertionConsumerServiceURL="{$acsUrl}">
    <saml:Issuer>{$spEntityId}</saml:Issuer>{$subjectStr}{$nameIdPolicyStr}{$requestedAuthnStr}
</samlp:AuthnRequest>
AUTHNREQUEST;

        // https://github.com/SAML-Toolkits/php-saml/pull/439
        if (isset($security['authnRequestsSigned']) && $security['authnRequestsSigned']) {
            // add signature to the authnRequest to prevent rejection
            try {
                $options =  array('id_name' => 'ID', 'overwrite' => false);
                $request = Utils::addSign($request, $spData['privateKey'], $spData['x509cert'], $security['signatureAlgorithm'], $security['digestAlgorithm'], $options);
            } catch (\Exception $exception) {
                throw new Error('Could not put signature in AuthnRequest Body, ' . $exception->getMessage());
            }
        }

        $this->_id = $id;
        $this->_authnRequest = $request;
    }

    /**
     * Returns deflated, base64 encoded, unsigned AuthnRequest.
     *
     * @param bool|null $deflate Whether or not we should 'gzdeflate' the request body before we return it.
     *
     * @return string
     */
    public function getRequest($deflate = null)
    {
        $subject = $this->_authnRequest;

        if (is_null($deflate)) {
            $deflate = $this->_settings->shouldCompressRequests();
        }

        if ($deflate) {
            $subject = gzdeflate($this->_authnRequest);
        }

        $base64Request = base64_encode($subject);
        return $base64Request;
    }

    /**
     * Returns the AuthNRequest ID.
     *
     * @return string
     */
    public function getId()
    {
        return $this->_id;
    }

    /**
     * Returns the XML that will be sent as part of the request
     *
     * @return string
     */
    public function getXML()
    {
        return $this->_authnRequest;
    }
}
