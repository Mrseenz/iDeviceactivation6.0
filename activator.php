<?php

declare(strict_types=1);

/**
 * Class ActivationGenerator
 *
 * Generates an Apple iDevice ActivationRecord plist.
 * This class emulates the response from Apple's activation server (albert.apple.com).
 * The structure and data are based on analysis of actual activation responses, with cryptographic
 * components generated dynamically, including a proper certificate chain of trust.
 */
class ActivationGenerator
{
    /**
     * @var array Holds the device information parsed from the activation request.
     */
    private array $deviceInfo;

    /** @var resource|\OpenSSLAsymmetricKey|false The Apple Root CA private key. */
    private $rootCaKey;
    /** @var string|null The Apple Root CA certificate. */
    private ?string $rootCaCert;

    /** @var resource|\OpenSSLAsymmetricKey|false The Device CA private key. */
    private $deviceCaKey;
    /** @var string|null The Device CA certificate. */
    private ?string $deviceCaCert;

    /** @var resource|\OpenSSLAsymmetricKey|false The server's private key for signing the AccountToken. */
    private $serverPrivateKey;
    /** @var string|null The server's X.509 certificate (AccountTokenCertificate). */
    private ?string $serverCertificate;

    /** @var resource|\OpenSSLAsymmetricKey|false The device's private key. */
    private $devicePrivateKey;
    /** @var string|null The device's X.509 certificate (DeviceCertificate). */
    private ?string $deviceCertificate;


    /**
     * Constructor.
     *
     * @param string $requestPlist The raw plist from the iDevice's activation request.
     */
    public function __construct(string $requestPlist)
    {
        $this->deviceInfo = $this->parseActivationRequest($requestPlist);
        
        // The cryptographic generation process follows a chain of trust.
        // 1. Create the Certificate Authorities (Root and Device CAs).
        $this->generateCaCredentials();
        // 2. Create the server certificate, signed by the Root CA.
        $this->generateServerCredentials();
        // 3. Create the device certificate, signed by the Device CA.
        $this->generateDeviceCredentials();
    }

    /**
     * Main public method to generate the complete ActivationRecord plist.
     *
     * @return string The complete XML plist as a string.
     */
    public function generate(): string
    {
        return $this->generateActivationRecord();
    }

    /**
     * Generates the complete ActivationRecord by orchestrating all components.
     *
     * @return string The complete XML plist string.
     */
    private function generateActivationRecord(): string
    {
        // 1. Generate the DeviceCertificate (already created in constructor, just retrieve it).
        $deviceCertificate = $this->generateDeviceCertificate();

        // 2. Generate the WildcardTicket.
        // This is a signed blob that authenticates the device to other Apple services.
        $wildcardTicket = $this->generateWildcardTicket();

        // 3. Generate the AccountToken payload and sign it.
        $accountTokenPayload = $this->generateAccountToken($wildcardTicket);
        $accountTokenSignature = $this->signData($accountTokenPayload);
        $accountToken = base64_encode($accountTokenPayload);

        // 4. Assemble all components into the final structure.
        $components = [
            'unbrick' => true,
            'AccountTokenCertificate' => $this->generateAccountTokenCertificate(),
            'DeviceCertificate' => $deviceCertificate,
            'RegulatoryInfo' => $this->generateRegulatoryInfo(),
            'FairPlayKeyData' => $this->generateFairPlayKeyData(), // Placeholder
            'AccountToken' => $accountToken,
            'AccountTokenSignature' => $accountTokenSignature,
            'UniqueDeviceCertificate' => $this->generateUniqueDeviceCertificate(), // Placeholder
        ];

        // 5. Construct and return the final XML plist.
        return $this->assembleActivationRecord($components);
    }
    
    /**
     * Generates the Certificate Authority (CA) credentials that form the root of our trust chain.
     * - A self-signed "Apple Root CA" is created.
     * - A "Device CA" is created and signed by the "Apple Root CA".
     */
    private function generateCaCredentials(): void
    {
        $config = ["digest_alg" => "sha256", "private_key_bits" => 2048, "private_key_type" => OPENSSL_KEYTYPE_RSA];

        // 1. Generate Apple Root CA (Self-Signed)
        $this->rootCaKey = openssl_pkey_new($config);
        if (!$this->rootCaKey) throw new \RuntimeException("Failed to generate Root CA private key.");
        
        $dn = ["organizationName" => "Apple Inc.", "commonName" => "Apple Root CA"];
        $csr = openssl_csr_new($dn, $this->rootCaKey, $config);
        if (!$csr) throw new \RuntimeException("Failed to generate Root CA CSR.");
        
        $x509 = openssl_csr_sign($csr, null, $this->rootCaKey, 3650, $config, ['serialNumber' => time()]);
        if (!$x509) throw new \RuntimeException("Failed to sign Root CA certificate.");
        
        openssl_x509_export($x509, $this->rootCaCert);

        // 2. Generate Device CA (Signed by Root CA)
        $this->deviceCaKey = openssl_pkey_new($config);
        if (!$this->deviceCaKey) throw new \RuntimeException("Failed to generate Device CA private key.");

        $dn = ["organizationName" => "Apple Inc.", "commonName" => "Apple Device CA"];
        $csr = openssl_csr_new($dn, $this->deviceCaKey, $config);
        if (!$csr) throw new \RuntimeException("Failed to generate Device CA CSR.");

        $x509 = openssl_csr_sign($csr, $this->rootCaCert, $this->rootCaKey, 2000, $config, ['serialNumber' => time() + 1]);
        if (!$x509) throw new \RuntimeException("Failed to sign Device CA certificate.");
        
        openssl_x509_export($x509, $this->deviceCaCert);
    }
    
    /**
     * Generates a new RSA private key and an X.509 certificate signed by the Root CA.
     * This acts as the activation server's certificate (`AccountTokenCertificate`).
     */
    private function generateServerCredentials(): void
    {
        $config = ["digest_alg" => "sha256", "private_key_bits" => 2048, "private_key_type" => OPENSSL_KEYTYPE_RSA];

        $this->serverPrivateKey = openssl_pkey_new($config);
        if (!$this->serverPrivateKey) throw new \RuntimeException("Failed to generate server private key.");

        $dn = [
            "countryName" => "US",
            "stateOrProvinceName" => "California",
            "localityName" => "Cupertino",
            "organizationName" => "Apple Inc.",
            "commonName" => "albert.apple.com",
        ];

        $csr = openssl_csr_new($dn, $this->serverPrivateKey, $config);
        if (!$csr) throw new \RuntimeException("Failed to generate server CSR.");

        // Sign the server certificate with the Root CA to establish trust.
        $x509 = openssl_csr_sign($csr, $this->rootCaCert, $this->rootCaKey, 365, $config, ['serialNumber' => time() + 2]);
        if (!$x509) throw new \RuntimeException("Failed to sign server certificate with Root CA.");

        openssl_x509_export($x509, $this->serverCertificate);
    }
    
    /**
     * Generates a new RSA private key and an X.509 certificate signed by the Device CA.
     * This simulates the device's unique hardware certificate (`DeviceCertificate`).
     */
    private function generateDeviceCredentials(): void
    {
        $config = ["digest_alg" => "sha256", "private_key_bits" => 2048, "private_key_type" => OPENSSL_KEYTYPE_RSA];

        $this->devicePrivateKey = openssl_pkey_new($config);
        if (!$this->devicePrivateKey) throw new \RuntimeException("Failed to generate device private key.");

        $dn = [
            "commonName" => $this->deviceInfo['SerialNumber'],
            "organizationalUnitName" => $this->deviceInfo['ProductType'],
            "organizationName" => "Apple Inc.",
        ];

        $csr = openssl_csr_new($dn, $this->devicePrivateKey, $config);
        if (!$csr) throw new \RuntimeException("Failed to generate device CSR.");

        // Sign the device certificate with the Device CA.
        $x509 = openssl_csr_sign($csr, $this->deviceCaCert, $this->deviceCaKey, 3650, $config, ['serialNumber' => time() + 3]);
        if (!$x509) throw new \RuntimeException("Failed to sign device certificate with Device CA.");

        openssl_x509_export($x509, $this->deviceCertificate);
    }

    /**
     * Parses the activation request from the device.
     *
     * @param string $requestPlist The raw request plist.
     * @return array An array of device properties.
     */
    private function parseActivationRequest(string $requestPlist): array
    {
        // Use SimpleXML to parse the incoming request from the iDevice.
        // The '@' suppresses warnings on invalid XML, which we handle manually.
        $xml = @simplexml_load_string($requestPlist);
        if ($xml === false || !isset($xml->dict)) {
            // Fallback to static data if parsing fails.
            error_log("Failed to parse activation request XML. Using fallback data.");
            return $this->getStaticDeviceInfo();
        }

        // Extract key-value pairs from the root dictionary.
        $deviceInfo = [];
        $dict = $xml->dict;
        $count = count($dict->key);
        for ($i = 0; $i < $count; $i++) {
            $key = (string)$dict->key[$i];
            $value = $dict->children()[$i * 2 + 1];
            $deviceInfo[$key] = (string)$value;
        }

        return $deviceInfo;
    }

    /**
     * @return array Static device info used as a fallback if parsing fails.
     */
    private function getStaticDeviceInfo(): array
    {
        return [
            'SerialNumber' => 'DNPF561P0F0N',
            'ProductType' => 'iPhone13,2',
            'UniqueDeviceID' => '00008101-000E714A3ED2001E',
			'MobileEquipmentIdentifier' => '35167292702814',
            'InternationalMobileEquipmentIdentity' => '351672927028143',
			'InternationalMobileEquipmentIdentity2' => '351672927211657',
            'IntegratedCircuitCardIdentity' => '89367000000011224869',
			'InternationalMobileSubscriberIdentity' => '655013671122486',
			'ActivationRandomness' => '7D45440A-F9F6-401B-B818-B673DD024F14',
        ];
    }
    
    /**
     * Returns the server certificate (`AccountTokenCertificate`), Base64 encoded.
     *
     * @return string Base64 encoded X.509 certificate.
     */
    private function generateAccountTokenCertificate(): string
    {
        return base64_encode($this->serverCertificate);
    }

    /**
     * Returns the device's hardware certificate (`DeviceCertificate`), Base64 encoded.
     *
     * @return string Base64 encoded X.509 certificate.
     */
    private function generateDeviceCertificate(): string
    {
        return base64_encode($this->deviceCertificate);
    }

    /**
     * Generates the modern ECC-based unique device certificate chain.
     * NOTE: This is a placeholder. A full implementation would require generating
     * an ECC certificate chain (device cert + intermediate CA cert) and concatenating them.
     *
     * @return string Base64 encoded data.
     */
    private function generateUniqueDeviceCertificate(): string
    {
        return 'LS0tLS1CRUdJTiBDRVJUSUZJQ0FURS0tLS0tCk1JSURqRENDQXpLZ0F3SUJBZ0lHQVpBUVloQWZNQW9HQ0NxR1NNNDlCQU1DTUVVeEV6QVJCZ05WQkFnTUNrTmgKYkdsbWIzSnVhV0V4RXpBUkJnTlZCQW9NQ2tGd2NHeGxJRWx1WXk0eEdUQVhCZ05WQkFNTUVFWkVVa1JETFZWRApVbFF0VTFWQ1EwRXdIaGNOTWpRd05qRXpNRFkwTmpJd1doY05NalF3TmpJd01EWTFOakl3V2pCdU1STXdFUVlEClZRUUlEQXBEWVd4cFptOXlibWxoTVJNd0VRWURWUVFLREFwQmNIQnNaU0JKYm1NdU1SNHdIQVlEVlFRTERCVjEKWTNKMElFeGxZV1lnUTJWeWRHbG1hV05oZEdVeElqQWdCZ05WQkFNTUdUQXdNREE0TURFd0xUQXdNVGcwT1VVMApNREEyUVRRek1qWXdXVEFUQmdjcWhrak9QUUlCQmdncWhrak9QUU1CQndOQ0FBU2xaeVJycFRTMEZWWGphYWdoCnJlMTh2RFJPd1ZEUEZMeC9CNzE2aXhqamZyaVMvcmhrN0xtOENHSXJmWWxlOTBobUV0YUdCSlBVOFM0UUhGRmgKL0d2U280SUI0ekNDQWQ4d0RBWURWUjBUQVFIL0JBSXdBREFPQmdOVkhROEJBZjhFQkFNQ0JQQXdnZ0ZNQmdrcQpoa2lHOTJOa0NnRUVnZ0U5TVlJQk9mK0VrcjJrUkFzd0NSWUVRazlTUkFJQkRQK0VtcUdTVUEwd0N4WUVRMGhKClVBSURBSUFRLzRTcWpaSkVFVEFQRmdSRlEwbEVBZ2NZU2VRQWFrTW0vNGFUdGNKakd6QVpGZ1JpYldGakJCRmoKTURwa01Eb3hNanBpTlRveVlqbzROLytHeTdYS2FSa3dGeFlFYVcxbGFRUVBNelUxTXpJME1EZzNPREkyTkRJeAovNGVieWR4dEZqQVVGZ1J6Y201dEJBeEdORWRVUjFsS1draEhOMGIvaDZ1UjBtUXlNREFXQkhWa2FXUUVLREJoCk5EWXpNRFZqWVRKbFl6Z3daamszWmpJNFlUSXlZamRpT1RjM1l6UTFZVEF4WXpneU9ISC9oN3Uxd21NYk1Ca1cKQkhkdFlXTUVFV013T21Rd09qRXlPbUkxT2pKaU9qZzIvNGVibGRKa09qQTRGZ1J6Wldsa0JEQXdOREk0TWtaRgpNelEyTTBVNE1EQXhOak15TURFeU56WXlNamt6T1RrNU56WkRRVVpHTkRrME5USTNSRVUyTVRFd01nWUtLb1pJCmh2ZGpaQVlCRHdRa01TTC9oT3FGbkZBS01BZ1dCRTFCVGxBeEFQK0Urb21VVUFvd0NCWUVUMEpLVURFQU1CSUcKQ1NxR1NJYjNZMlFLQWdRRk1BTUNBUUF3SndZSktvWklodmRqWkFnSEJBbE1MU2w5aG9ZeTE3dVFld0IKZ1pqc2hZeitkemlXU2I4U2tRQzdFZEZZM0Z2bWswQXE3ZlVnY3JhcTZqU1g4MUZWcXc1bjNpRlQwc0NRSXhibgpBQkVCQ1JZazlodFlML3RlZ0kzc29DeUZzcmM1TTg1OXhTcHRGNFh2ejU1UVZDQkw1OFdtSzZnVFNjVHlVSDN3CjJSVERXUjNGRnJxR2Y3aTVCV1lxRVdLMEkzNFgyTWJsZnR4OTM3bmI3SysrTFVkYk81YnFZaDM0bTREcUZwbCsKZkRnaDVtdU1DNkVlWWZPeTlpdEJsbE5ad2VlUWJBUmtKa2FHUGJ5aEdpYlNCcTZzR0NrQVJ2WTltT2ZNT3hZYgplWitlNnhBRmZ4MjFwUk9BM0xZc0FmMzBycmtRc0tKODVBRHZVMzFKdUFibnpmeGQzRnorbHBXRi9FeHU5QVNtCm1XcFFTY1VZaXF5TXZHUWQ5Rnl6ZEtNYk1SQ1ExSWpGZVhOUWhWQTY0VzY4M0czbldzRjR3a3lFRHl5RnI1N2QKcUJ3dFA4djRhSXh4ZHVSODVaT0lScWs0UGlnVlUvbVRpVUVQem16Wlh2MVB3ZzNlOGpjL3pZODZoYWZHaDZsZApMbHAyTU9uakNuN1pmKzFFN0RpcTNrS280bVo0MHY0cEJOV1BodnZGZ0R5WDdSLy9UaTBvbCtnbzc1QmR2b1NpCmljckUzYUdOc0hhb0d6cE90SHVOdW5HNTh3UW9BWXMwSUhQOGNvdmxPMDhHWHVRUlh1NVYyM1VyK2ZLQ2t5dm8KSEptYWVmL29ZbmR3QzAvK1pUL2FOeTZKUUEzUzg1Y3dzaFE3YXpYajlZazNndzkzcE0xN3I5dExGejNHWDRQegoyZWhMclVOTCtZcSs1bW1zeTF6c2RlcENGMldkR09KbThnajluMjdHUDNVVnhUOVA4TkI0K1YwNzlEWXd6TEdiCjhLdGZCRExSM2cwSXppYkZQNzZ5VC9FTDUwYmlacU41SlNLYnoxS2lZSGlGS05CYnJEbDlhWWFNdnFJNHhOblgKNVdpZk43WDk3UHE0TFQzYW5rcmhUZUVqeXFxeC9kYmovMGh6bG1RRCtMaW5UV29SU2ZFVWI2Ni9peHFFb3BrbQp3V2h6dXZPMUVPaTRseUJUV09MdmxUY1h1WUpwTUpRZHNCb0dkSVdrbm80Qnp5N3BESXMvSXpNUVEzaUpEYVc3CnBiTldrSUNTdytEVWJPdDVXZFZqN0FHTEFUR2FVRW1ZS1dZNnByclo2bks4S1lReFJDN3NvdDc2SHJaajJlVnoKRVl4cm1hVy9lRHhuYVhDOGxCNXpCS0wrQ1pDVmZhWHlEdmV1MGQvdzhpNGNnRTVqSkF6S2FFcmtDeUlaSm5KdApYTkJhOEl3M3Y3aWGNlhPREFEaU9KK3hGTjdJQXlzem5YMEw4RFJ6Mkc1d2I5clllMW03eDRHM3duaklxZG1hCm9DdzZINnNPcFFRM2RWcVd0UDhrL1FJbk5ONnV2dVhEN3kvblVsdlVqcnlVbENlcFlzeDhkOFNScWw1M3d0SGwKYWxabUpvRWh0QTdRVDBUZHVVUmJ6M2dabWVXKzJRM3BlazVHaVBKRStkci83YklHRGxhdWZJVkVQTXc4clg3agpVNTVRWmZ6MHZyc3p5eGg3U0x1SDc3RmVGd3ljVlJId0t6NkFndlpOb0R2b0dMWk9KTi82V1NxVlhmczYxUEdPCmN0d29WVkkzejhYMGtWUXRHeUpjQTlFYjN0SFBHMzMrM1RpYnBsL2R0VW1LRU5WeUUrQTJUZDN5RFRydVBFQmsKZHJhM3pFc25ZWXFxR2I3aVhvMVB6Y3crUGo5QTRpQlE2cTl3RGtBbEFDdTZsZnUwCi0tLS0tRU5EIENPTlRBSU5FUi0tLS0tCg==';
    }

    /**
     * Generates regulatory information (e-labels).
     *
     * @return string Base64 encoded JSON string.
     */
    private function generateRegulatoryInfo(): string
    {
        $regulatoryData = ['elabel' => ['bis' => ['regulatory' => 'R-41094897']]];
        return base64_encode(json_encode($regulatoryData));
    }

    /**
     * Generates FairPlay DRM key data.
     * NOTE: This is a placeholder. The real data is a proprietary binary format.
     *
     * @return string Base64 encoded proprietary binary data.
     */
    private function generateFairPlayKeyData(): string
    {
        return 'LS0tLS1CRUdJTiBDT05UQUlORVItLS0tLQpBQUVBQVQzOGVycGgzbW9HSGlITlFTMU5YcTA1QjFzNUQ2UldvTHhRYWpKODVDWEZLUldvMUI2c29Pd1kzRHUyClJtdWtIemlLOFV5aFhGV1N1OCtXNVI4dEJtM3MrQ2theGpUN2hnQVJ5S0o0U253eE4vU3U2aW9ZeDE3dVFld0IKZ1pqc2hZeitkemlXU2I4U2tRQzdFZEZZM0Z2bWswQXE3ZlVnY3JhcTZqU1g4MUZWcXc1bjNpRlQwc0NRSXhibgpBQkVCQ1JZazlodFlML3RlZ0kzc29DeUZzcmM1TTg1OXhTcHRGNFh2ejU1UVZDQkw1OFdtSzZnVFNjVHlVSDN3CjJSVERXUjNGRnJxR2Y3aTVCV1lxRVdLMEkzNFgyTWJsZnR4OTM3bmI3SysrTFVkYk81YnFZaDM0bTREcUZwbCsKZkRnaDVtdU1DNkVlWWZPeTlpdEJsbE5ad2VlUWJBUmtKa2FHUGJ5aEdpYlNCcTZzR0NrQVJ2WTltT2ZNT3hZYgplWitlNnhBRmZ4MjFwUk9BM0xZc0FmMzBycmtRc0tKODVBRHZVMzFKdUFibnpmeGQzRnorbHBXRi9FeHU5QVNtCm1XcFFTY1VZaXF5TXZHUWQ5Rnl6ZEtNYk1SQ1ExSWpGZVhOUWhWQTY0VzY4M0czbldzRjR3a3lFRHl5RnI1N2QKcUJ3dFA4djRhSXh4ZHVSODVaT0lScWs0UGlnVlUvbVRpVUVQem16Wlh2MVB3ZzNlOGpjL3pZODZoYWZHaDZsZApMbHAyTU9uakNuN1pmKzFFN0RpcTNrS280bVo0MHY0cEJOV1BodnZGZ0R5WDdSLy9UaTBvbCtnbzc1QmR2b1NpCmljckUzYUdOc0hhb0d6cE90SHVOdW5HNTh3UW9BWXMwSUhQOGNvdmxPMDhHWHVRUlh1NVYyM1VyK2ZLQ2t5dm8KSEptYWVmL29ZbmR3QzAvK1pUL2FOeTZKUUEzUzw1Y3dzaFE3YXpYajlZazNndzkzcE0xN3I5dExGejNHWDRQegoyZWhMclVOTCtZcSs1bW1zeTF6c2RlcENGMldkR09KbThnajluMjdHUDNVVnhUOVA4TkI0K1YwNzlEWXd6TEdiCjhLdGZCRExSM2cwSXppYkZQNzZ5VC9FTDUwYmlacU41SlNLYnoxS2lZSGlGS05CYnJEbDlhWWFNdnFJNHhOblgKNVdpZk43WDk3UHE0TFQzYW5rcmhUZUVqeXFxeC9kYmovMGh6bG1RRCtMaW5UV29SU2ZFVWI2Ni9peHFFb3BrbQp3V2h6dXZPMUVPaTRseUJUV09MdmxUY1h1WUpwTUpRZHNCb0dkSVdrbm80Qnp5N3BESXMvSXpNUVEzaUpEYVc3CnBiTldrSUNTdytEVWJPdDVXZFZqN0FHTEFUR2FVRW1ZS1dZNnByclo2bks0S1lReFJDN3NvdDc2SHJaajJlVnoKRVl4cm1hVy9lRHhuYVhDOGxCNXpCS0wrQ1pDVmZhWHlEdmV1MGQvdzhpNGNnRTVqSkF6S2FFcmtDeUlaSm5KdApYTkJhOEl3M3Y3aWaZUJOREFEaU9KK3hGTjdJQXlzem5YMEw4RFJ6Mkc1d2I5clllMW03eDRHM3duaklxZG1hCm9DdzZINnNPcFFRM2RWcVd0UDhrL1FJbk5ONnV2dVhEN3kvblVsdlVqcnlVbENlcFlxeDhkOFNScWw1M3d0SGwKYWxabUpvRWh0QTdRVDBUZHVVUmJ6M2dabWVXKzJRM3BlazVHaVBKRStkci83YklHRGxhdWZJVkVQTXc4clg3agpVNTVRWmZ6MHZyc3p5eGg3U0x1SDc3RmVGd3ljVlJId0t6NkFndlpOb0R2b0dMWk9KTi82V1NxVlhmczYxUEdPCmN0d29WVkkzejhYMGtWUXRHeUpjQTlFYjN0SFBHMzMrM1RpYnBsL2R0VW1LRU5WeUUrQTJUZDN5RFRydVBFQmsKZHJhM3pFc25ZWXFxR2I3aVhvMVB6Y3crUGo5QTRpQlE2cTl3RGtBbEFDdTZsZnUwCi0tLS0tRU5EIENPTlRBSU5FUi0tLS0tCg==';
    }

    /**
     * Generates the WildcardTicket by creating and signing a PKCS#7 data blob.
     *
     * @return string Base64 encoded signed ticket.
     */
    private function generateWildcardTicket(): string
    {
        // The WildcardTicket authenticates the device for other services.
        // It's a PKCS#7 signed blob containing device identifiers.
        // We sign it with the server's key, as the server is issuing the ticket.
        $ticketContent = json_encode([
            'UniqueDeviceID' => $this->deviceInfo['UniqueDeviceID'],
            'ActivationRandomness' => $this->deviceInfo['ActivationRandomness'],
            'timestamp' => time(),
        ]);

        $dataFile = tempnam(sys_get_temp_dir(), 'wdt_data');
        $signedFile = tempnam(sys_get_temp_dir(), 'wdt_signed');
        if ($dataFile === false || $signedFile === false) {
             throw new \RuntimeException("Failed to create temporary files for WildcardTicket signing.");
        }
        
        try {
            file_put_contents($dataFile, $ticketContent);

            $success = openssl_pkcs7_sign(
                $dataFile,
                $signedFile,
                $this->serverCertificate,
                $this->serverPrivateKey,
                [], // No extra headers
                PKCS7_BINARY // Output in binary DER format for the plist
            );

            if (!$success) {
                throw new \RuntimeException("Failed to sign WildcardTicket data using openssl_pkcs7_sign.");
            }

            $signedData = file_get_contents($signedFile);
            if ($signedData === false) {
                 throw new \RuntimeException("Failed to read signed WildcardTicket data from temp file.");
            }
        } finally {
            // Clean up temporary files
            unlink($dataFile);
            unlink($signedFile);
        }

        return base64_encode($signedData);
    }
    
    /**
     * Generates the AccountToken payload string.
     *
     * @param string $wildcardTicket The pre-generated, Base64-encoded WildcardTicket.
     * @return string The raw property-list-like payload string.
     */
    private function generateAccountToken(string $wildcardTicket): string
    {
        // This is the core data payload containing device identifiers and activation
        // parameters. Its integrity is guaranteed by the AccountTokenSignature.
        $tokenData = [
		'InternationalMobileEquipmentIdentity' => $this->deviceInfo['InternationalMobileEquipmentIdentity'],
		'ActivationTicket' = 'MIIBkgIBATAKBggqhkjOPQQDAzGBn58/BKcA1TCfQAThQBQAn0sUYMeqwt5j6cNdU5ZeFkUyh+Fnydifh20HNWIoMpSJJp+IAAc1YigyaTIzn5c9GAAAAADu7u7u7u7u7xAAAADu7u7u7u7u75+XPgQAAAAAn5c/BAEAAACfl0AEAQAAAJ+XRgQGAAAAn5dHBAEAAACfl0gEAAAAAJ+XSQQBAAAAn5dLBAAAAACfl0wEAQAAAARnMGUCMDf5D2EOrSirzH8zQqox7r+Ih8fIaZYjFj7Q8gZChvnLmUgbX4t7sy/sKFt+p6ZnbQIxALyXlWNh9Hni+bTkmIzkfjGhw1xNZuFATlEpORJXSJAAifzq3GMirueuNaJ339NrxqN2MBAGByqGSM49AgEGBSuBBAAiA2IABA4mUWgS86Jmr2wSbV0S8OZDqo4aLqO5jzmX2AGBh9YHIlyRqitZFvB8ytw2hBwR2JjF/7sorfMjpzCciukpBenBeaiaL1TREyjLR8OuJEtUHk8ZkDE2z3emSrGQfEpIhQ==',
		'PhoneNumberNotificationURL' = 'https://albert.apple.com/deviceservices/phoneHome';
		'InternationalMobileSubscriberIdentity' => $this->deviceInfo['InternationalMobileSubscriberIdentity'],
		'ProductType' => $this->deviceInfo['ProductType'],
		'UniqueDeviceID' => $this->deviceInfo['UniqueDeviceID'],
		'SerialNumber' => $this->deviceInfo['SerialNumber'],
		'MobileEquipmentIdentifier' => $this->deviceInfo['MobileEquipmentIdentifier'],
		'InternationalMobileEquipmentIdentity2' => $this->deviceInfo['InternationalMobileEquipmentIdentity2'],
		'PostponementInfo' => new stdClass(), // Represents an empty dict {}
		'ActivationRandomness' => $this->deviceInfo['ActivationRandomness'],
		'ActivityURL' = 'https://albert.apple.com/deviceservices/activity';
		'IntegratedCircuitCardIdentity' => $this->deviceInfo['IntegratedCircuitCardIdentity'],
        ];
        
        $tokenString = "{\n";
        foreach ($tokenData as $key => $value) {
            if ($key === 'PostponementInfo') {
                $tokenString .= "\t\"{$key}\" = {};\n";
            } else {
                $tokenString .= "\t\"{$key}\" = \"{$value}\";\n";
            }
        }
        $tokenString .= "}";

        return $tokenString;
    }

    /**
     * Signs the given data payload using the server's private key.
     *
     * @param string $data The raw string data to be signed.
     * @return string The Base64 encoded RSA-SHA256 signature.
     */
    private function signData(string $data): string
    {
        $signature = '';
        $success = openssl_sign($data, $signature, $this->serverPrivateKey, OPENSSL_ALGO_SHA256);
        
        if (!$success) {
            throw new \RuntimeException("Failed to sign data for AccountTokenSignature.");
        }

        return base64_encode($signature);
    }

    /**
     * Assembles the final ActivationRecord plist from its components.
     *
     * @param array $components An associative array of the plist components.
     * @return string The final XML plist string.
     */
    private function assembleActivationRecord(array $components): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->standalone = true;
        $doc->formatOutput = true;

        $doctype = new DOMDocumentType('plist', '-//Apple//DTD PLIST 1.0//EN', 'http://www.apple.com/DTDs/PropertyList-1.0.dtd');
        $doc->appendChild($doctype);

        $plist = $doc->createElement('plist');
        $plist->setAttribute('version', '1.0');
        $doc->appendChild($plist);

        $rootDict = $doc->createElement('dict');
        $plist->appendChild($rootDict);

        $rootDict->appendChild($doc->createElement('key', 'ActivationRecord'));
        $activationRecordDict = $doc->createElement('dict');
        $rootDict->appendChild($activationRecordDict);

        foreach ($components as $key => $value) {
            $activationRecordDict->appendChild($doc->createElement('key', $key));
            if (is_bool($value)) {
                $activationRecordDict->appendChild($doc->createElement($value ? 'true' : 'false'));
            } elseif (is_string($value)) {
                $activationRecordDict->appendChild($doc->createElement('data', $value));
            }
        }
        
        $xml = $doc->saveXML();
        if ($xml === false) {
             throw new \RuntimeException("Failed to save final XML plist.");
        }
        return $xml;
    }
}
