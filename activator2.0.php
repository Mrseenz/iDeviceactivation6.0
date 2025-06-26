<?php

declare(strict_types=1);

// Same ActivationGenerator class as in activator.php
// For brevity, imagine the entire ActivationGenerator class is copied here.
// We will refer to it as if it's present.
// TODO: In a real scenario, this would be require_once 'ActivationGenerator.php';
// And ActivationGenerator.php would be created from activator.php's class.

/**
 * Class ActivationGenerator
 * (Copied from activator.php - responsible for generating the actual activation record plist)
 */
class ActivationGenerator
{
    private array $deviceInfo;
    private $rootCaKey;
    private ?string $rootCaCert;
    private $deviceCaKey;
    private ?string $deviceCaCert;
    private $serverPrivateKey;
    private ?string $serverCertificate;
    private $devicePrivateKey;
    private ?string $deviceCertificate;

    public function __construct(string $requestPlist)
    {
        $this->deviceInfo = $this->parseActivationRequest($requestPlist);
        if (empty($this->deviceInfo['SerialNumber']) || empty($this->deviceInfo['ProductType']) || empty($this->deviceInfo['UniqueDeviceID'])) {
            throw new \RuntimeException("Essential device information (SerialNumber, ProductType, UniqueDeviceID) missing from request for certificate generation.");
        }
        $this->generateCaCredentials();
        $this->generateServerCredentials();
        $this->generateDeviceCredentials();
    }

    public function generate(): string
    {
        return $this->generateActivationRecord();
    }

    private function generateActivationRecord(): string
    {
        $deviceCertificate = base64_encode($this->deviceCertificate); // Already created
        $wildcardTicket = $this->generateWildcardTicket();
        $accountTokenPayload = $this->generateAccountToken($wildcardTicket);
        $accountTokenSignature = $this->signData($accountTokenPayload);
        $accountToken = base64_encode($accountTokenPayload);

        $components = [
            'unbrick' => true,
            'AccountTokenCertificate' => base64_encode($this->serverCertificate),
            'DeviceCertificate' => $deviceCertificate,
            'RegulatoryInfo' => $this->generateRegulatoryInfo(),
            'FairPlayKeyData' => $this->generateFairPlayKeyData(),
            'AccountToken' => $accountToken,
            'AccountTokenSignature' => $accountTokenSignature,
            'UniqueDeviceCertificate' => $this->generateUniqueDeviceCertificate(),
        ];
        return $this->assembleActivationRecord($components);
    }

    private function parseActivationRequest(string $requestPlist): array
    {
        $xml = @simplexml_load_string($requestPlist);
        if ($xml === false) { // Allow empty dict for initial parse, but check keys later.
            throw new \RuntimeException("Failed to parse activation request XML. Input is not valid XML.");
        }
        if (!isset($xml->dict)) { // Check if root is a dict
             // Try to find dict inside ActivationInfoXML if that's the case.
             // This handles the case where the $requestPlist is the *outer* XML from multipart.
            if (isset($xml->key) && (string)$xml->key === 'ActivationInfoXML' && isset($xml->data)) {
                $innerPlist = base64_decode((string)$xml->data);
                if ($innerPlist) {
                    $xml = @simplexml_load_string($innerPlist);
                    if ($xml === false || !isset($xml->dict)) {
                         throw new \RuntimeException("Failed to parse inner ActivationInfoXML plist.");
                    }
                } else {
                    throw new \RuntimeException("Failed to decode ActivationInfoXML data.");
                }
            } else {
                // If it's not the specific ActivationInfoXML structure and not a dict at root, it's invalid.
                error_log("Activation request XML does not have a root dictionary or expected structure.");
                // Return empty array, constructor will check for essential keys.
                return [];
            }
        }


        $deviceInfo = [];
        $dict = $xml->dict;
        $count = count($dict->key);
        for ($i = 0; $i < $count; $i++) {
            $keyNode = $dict->key[$i];
            $valueNode = $dict->children()[$i * 2 + 1]; // key, value, key, value ...
            $key = (string)$keyNode;

            // Handle various value types in plist
            if ($valueNode->getName() === 'string') {
                $deviceInfo[$key] = (string)$valueNode;
            } elseif ($valueNode->getName() === 'integer') {
                $deviceInfo[$key] = (int)(string)$valueNode;
            } elseif ($valueNode->getName() === 'true') {
                $deviceInfo[$key] = true;
            } elseif ($valueNode->getName() === 'false') {
                $deviceInfo[$key] = false;
            } elseif ($valueNode->getName() === 'data') {
                $deviceInfo[$key] = (string)$valueNode; // Keep as base64 string for now
            } elseif ($valueNode->getName() === 'date') {
                $deviceInfo[$key] = (string)$valueNode; // ISO 8601 date string
            } elseif ($valueNode->getName() === 'dict') {
                // For simplicity in this generator, we might not need deep dict parsing
                // but if a specific sub-dict is needed, it should be handled.
                // For now, we'll skip parsing sub-dictionaries deeply unless required by a specific key.
                // If ActivationRequestInfo is a sub-dict, parse it:
                if ($key === 'ActivationRequestInfo' || $key === 'DeviceInfo' || $key === 'BasebandRequestInfo' || $key === 'DeviceID') {
                    $subDict = $valueNode;
                    $subCount = count($subDict->key);
                     for ($j = 0; $j < $subCount; $j++) {
                        $subKeyNode = $subDict->key[$j];
                        $subValueNode = $subDict->children()[$j * 2 + 1];
                        $deviceInfo[(string)$subKeyNode] = (string)$subValueNode; // Simplified: convert all sub-values to string
                    }
                } else {
                     $deviceInfo[$key] = "[dict]"; // Placeholder for unparsed dict
                }
            } else {
                // For other types like array, just store a placeholder or string representation
                 $deviceInfo[$key] = "[" . $valueNode->getName() . "]";
            }
        }

        // Fallback for critical info if not found after parsing (e.g. from static data for testing)
        // This is just for ensuring the generator doesn't break immediately if some keys are missing
        // from a valid but minimal plist. A real server would be stricter.
        $defaults = $this->getStaticDeviceInfo();
        foreach (['SerialNumber', 'ProductType', 'UniqueDeviceID', 'ActivationRandomness',
                  'InternationalMobileEquipmentIdentity', 'InternationalMobileSubscriberIdentity',
                  'MobileEquipmentIdentifier', 'InternationalMobileEquipmentIdentity2',
                  'IntegratedCircuitCardIdentity'] as $defaultKey) {
            if (!isset($deviceInfo[$defaultKey]) && isset($defaults[$defaultKey])) {
                $deviceInfo[$defaultKey] = $defaults[$defaultKey];
            }
        }
        return $deviceInfo;
    }

    private function getStaticDeviceInfo(): array // Used for fallbacks ONLY
    {
        return [
            'SerialNumber' => 'C00000000000', // Placeholder
            'ProductType' => 'iPhone0,0', // Placeholder
            'UniqueDeviceID' => '0000000000000000000000000000000000000000', // Placeholder
            'ActivationRandomness' => '00000000-0000-0000-0000-000000000000', // Placeholder
            'InternationalMobileEquipmentIdentity' => '000000000000000',
            'InternationalMobileSubscriberIdentity' => '000000000000000',
            'MobileEquipmentIdentifier' => '00000000000000',
            'InternationalMobileEquipmentIdentity2' => '000000000000000',
            'IntegratedCircuitCardIdentity' => '00000000000000000000',
        ];
    }

    private function generateCaCredentials(): void { /* ... same as activator.php ... */
        $config = ["digest_alg" => "sha256", "private_key_bits" => 2048, "private_key_type" => OPENSSL_KEYTYPE_RSA];
        $this->rootCaKey = openssl_pkey_new($config);
        if (!$this->rootCaKey) throw new \RuntimeException("Failed to generate Root CA private key.");
        $dn = ["organizationName" => "Apple Inc.", "commonName" => "Apple Root CA"];
        $csr = openssl_csr_new($dn, $this->rootCaKey, $config);
        if (!$csr) throw new \RuntimeException("Failed to generate Root CA CSR.");
        $x509 = openssl_csr_sign($csr, null, $this->rootCaKey, 3650, $config, ['serialNumber' => time()]);
        if (!$x509) throw new \RuntimeException("Failed to sign Root CA certificate.");
        openssl_x509_export($x509, $this->rootCaCert);

        $this->deviceCaKey = openssl_pkey_new($config);
        if (!$this->deviceCaKey) throw new \RuntimeException("Failed to generate Device CA private key.");
        $dn = ["organizationName" => "Apple Inc.", "commonName" => "Apple Device CA"];
        $csr = openssl_csr_new($dn, $this->deviceCaKey, $config);
        if (!$csr) throw new \RuntimeException("Failed to generate Device CA CSR.");
        $x509 = openssl_csr_sign($csr, $this->rootCaCert, $this->rootCaKey, 2000, $config, ['serialNumber' => time() + 1]);
        if (!$x509) throw new \RuntimeException("Failed to sign Device CA certificate.");
        openssl_x509_export($x509, $this->deviceCaCert);
    }
    private function generateServerCredentials(): void { /* ... same as activator.php ... */
        $config = ["digest_alg" => "sha256", "private_key_bits" => 2048, "private_key_type" => OPENSSL_KEYTYPE_RSA];
        $this->serverPrivateKey = openssl_pkey_new($config);
        if (!$this->serverPrivateKey) throw new \RuntimeException("Failed to generate server private key.");
        $dn = ["countryName" => "US", "stateOrProvinceName" => "California", "localityName" => "Cupertino", "organizationName" => "Apple Inc.", "commonName" => "albert.apple.com"];
        $csr = openssl_csr_new($dn, $this->serverPrivateKey, $config);
        if (!$csr) throw new \RuntimeException("Failed to generate server CSR.");
        $x509 = openssl_csr_sign($csr, $this->rootCaCert, $this->rootCaKey, 365, $config, ['serialNumber' => time() + 2]);
        if (!$x509) throw new \RuntimeException("Failed to sign server certificate with Root CA.");
        openssl_x509_export($x509, $this->serverCertificate);
    }
    private function generateDeviceCredentials(): void { /* ... same as activator.php ... */
        $config = ["digest_alg" => "sha256", "private_key_bits" => 2048, "private_key_type" => OPENSSL_KEYTYPE_RSA];
        $this->devicePrivateKey = openssl_pkey_new($config);
        if (!$this->devicePrivateKey) throw new \RuntimeException("Failed to generate device private key.");
         $dn = [
            "commonName" => $this->deviceInfo['SerialNumber'] ?? 'UnknownSN',
            "organizationalUnitName" => $this->deviceInfo['ProductType'] ?? 'UnknownPT',
            "organizationName" => "Apple Inc.",
        ];
        $csr = openssl_csr_new($dn, $this->devicePrivateKey, $config);
        if (!$csr) throw new \RuntimeException("Failed to generate device CSR.");
        $x509 = openssl_csr_sign($csr, $this->deviceCaCert, $this->deviceCaKey, 3650, $config, ['serialNumber' => time() + 3]);
        if (!$x509) throw new \RuntimeException("Failed to sign device certificate with Device CA.");
        openssl_x509_export($x509, $this->deviceCertificate);
    }
    private function generateRegulatoryInfo(): string { /* ... same as activator.php ... */
        return base64_encode(json_encode(['elabel' => ['bis' => ['regulatory' => 'R-41094897']]]));
    }
    private function generateFairPlayKeyData(): string { /* ... same as activator.php ... */
        return 'LS0tLS1CRUdJTiBDT05UQUlORVItLS0tLQpBQUVBQVQzOGVycGgzbW9HSGlITlFTMU5YcTA1QjFzNUQ2UldvTHhRYWpKODVDWEZLUldvMUI2c29Pd1kzRHUyClJtdWtIemlLOFV5aFhGV1N1OCtXNVI4dEJtM3MrQ2theGpUN2hnQVJ5S0o0U253eE4vU3U2aW9ZeDE3dVFld0IKZ1pqc2hZeitkemlXU2I4U2tRQzdFZEZZM0Z2bWswQXE3ZlVnY3JhcTZqU1g4MUZWcXc1bjNpRlQwc0NRSXhibgpBQkVCQ1JZazlodFlML3RlZ0kzc29DeUZzcmM1TTg1OXhTcHRGNFh2ejU1UVZDQkw1OFdtSzZnVFNjVHlVSDN3CjJSVERXUjNGRnJxR2Y3aTVCV1lxRVdLMEkzNFgyTWJsZnR4OTM3bmI3SysrTFVkYk81YnFZaDM0bTREcUZwbCsKZkRnaDVtdU1DNkVlWWZPeTlpdEJsbE5ad2VlUWJBUmtKa2FHUGJ5aEdpYlNCcTZzR0NrQVJ2WTltT2ZNT3hZYgplWitlNnhBRmZ4MjFwUk9BM0xZc0FmMzBycmtRc0tKODVBRHZVMzFKdUFibnpmeGQzRnorbHBXRi9FeHU5QVNtCm1XcFFTY1VZaXF5TXZHUWQ5Rnl6ZEtNYk1SQ1ExSWpGZVhOUWhWQTY0VzY4M0czbldzRjR3a3lFRHl5RnI1N2QKcUJ3dFA4djRhSXh4ZHVSODVaT0lScWs0UGlnVlUvbVRpVUVQem16Wlh2MVB3ZzNlOGpjL3pZODZoYWZHaDZsZApMbHAyTU9uakNuN1pmKzFFN0RpcTNrS280bVo0MHY0cEJOV1BodnZGZ0R5WDdSLy9UaTBvbCtnbzc1QmR2b1NpCmljckUzYUdOc0hhb0d6cE90SHVOdW5HNTh3UW9BWXMwSUhQOGNvdmxPMDhHWHVRUlh1NVYyM1VyK2ZLQ2t5dm8KSEptYWVmL29ZbmR3QzAvK1pUL2FOeTZKUUEzUzw1Y3dzaFE3YXpYajlZazNndzkzcE0xN3I5dExGejNHWDRQegoyZWhMclVOTCtZcSs1bW1zeTF6c2RlcENGMldkR09KbThnajluMjdHUDNVVnhUOVA4TkI0K1YwNzlEWXd6TEdiCjhLdGZCRExSM2cwSXppYkZQNzZ5VC9FTDUwYmlacU41SlNLYnoxS2lZSGlGS05CYnJEbDlhWWFNdnFJNHhOblgKNVdpZk43WDk3UHE0TFQzYW5rcmhUZUVqeXFxeC9kYmovMGh6bG1RRCtMaW5UV29SU2ZFVWI2Ni9peHFFb3BrbQp3V2h6dXZPMUVPaTRseUJUV09MdmxUY1h1WUpwTUpRZHNCb0dkSVdrbm80Qnp5N3BESXMvSXpNUVEzaUpEYVc3CnBiTldrSUNTdytEVWJPdDVXZFZqN0FHTEFUR2FVRW1ZS1dZNnByclo2bks0S1lReFJDN3NvdDc2SHJaajJlVnoKRVl4cm1hVy9lRHhuYVhDOGxCNXpCS0wrQ1pDVmZhWHlEdmV1MGQvdzhpNGNnRTVqSkF6S2FFcmtDeUlaSm5KdApYTkJhOEl3M3Y3aWaZUJOREFEaU9KK3hGTjdJQXlzem5YMEw4RFJ6Mkc1d2I5clllMW03eDRHM3duaklxZG1hCm9DdzZINnNPcFFRM2RWcVd0UDhrL1FJbk5ONnV2dVhEN3kvblVsdlVqcnlVbENlcFlxeDhkOFNScWw1M3d0SGwKYWxabUpvRWh0QTdRVDBUZHVVUmJ6M2dabWVXKzJRM3BlazVHaVBKRStkci83YklHRGxhdWZJVkVQTXc4clg3agpVNTVRWmZ6MHZyc3p5eGg3U0x1SDc3RmVGd3ljVlJId0t6NkFndlpOb0R2b0dMWk9KTi82V1NxVlhmczYxUEdPCmN0d29WVkkzejhYMGtWUXRHeUpjQTlFYjN0SFBHMzMrM1RpYnBsL2R0VW1LRU5WeUUrQTJUZDN5RFRydVBFQmsKZHJhM3pFc25ZWXFxR2I3aVhvMVB6Y3crUGo5QTRpQlE2cTl3RGtBbEFDdTZsZnUwCi0tLS0tRU5EIENPTlRBSU5FUi0tLS0tCg==';
    }
    private function generateUniqueDeviceCertificate(): string { /* ... same as activator.php ... */
        return 'LS0tLS1CRUdJTiBDRVJUSUZJQ0FURS0tLS0tCk1JSURqRENDQXpLZ0F3SUJBZ0lHQVpBUVloQWZNQW9HQ0NxR1NNNDlCQU1DTUVVeEV6QVJCZ05WQkFnTUNrTmgKYkdsbWIzSnVhV0V4RXpBUkJnTlZCQW9NQ2tGd2NHeGxJRWx1WXk0eEdUQVhCZ05WQkFNTUVFWkVVa1JETFZWRApVbFF0VTFWQ1EwRXdIaGNOTWpRd05qRXpNRFkwTmpJd1doY05NalF3TmpJd01EWTFOakl3V2pCdU1STXdFUVlEClZRUUlEQXBEWVd4cFptOXlibWxoTVJNd0VRWURWUVFLREFwQmNIQnNaU0JKYm1NdU1SNHdIQVlEVlFRTERCVjEKWTNKMElFeGxZV1lnUTJWeWRHbG1hV05oZEdVeElqQWdCZ05WQkFNTUdUQXdNREE0TURFd0xUQXdNVGcwT1VVMApNREEyUVRRek1qWXdXVEFUQmdjcWhrak9QUUlCQmdncWhrak9QUU1CQndOQ0FBU2xaeVJycFRTMEZWWGphYWdoCnJlMTh2RFJPd1ZEUEZMeC9CNzE2aXhqamZyaVMvcmhrN0xtOENHSXJmWWxlOTBobUV0YUdCSlBVOFM0UUhGRmgKL0d2U280SUI0ekNDQWQ4d0RBWURWUjBUQVFIL0JBSXdBREFPQmdOVkhROEJBZjhFQkFNQ0JQQXdnZ0ZNQmdrcQpoa2lHOTJOa0NnRUVnZ0U5TVlJQk9mK0VrcjJrUkFzd0NSWUVRazlTUkFJQkRQK0VtcUdTVUEwd0N4WUVRMGhKClVBSURBSUFRLzRTcWpaSkVFVEFQRmdSRlEwbEVBZ2NZU2VRQWFrTW0vNGFUdGNKakd6QVpGZ1JpYldGakJCRmoKTURwa01Eb3hNanBpTlRveVlqbzROLytHeTdYS2FSa3dGeFlFYVcxbGFRUVBNelUxTXpJME1EZzNPREkyTkRJeAovNGVieWR4dEZqQVVGZ1J6Y201dEJBeEdORWRVUjFsS1draEhOMGIvaDZ1UjBtUXlNREFXQkhWa2FXUUVLREJoCk5EWXpNRFZqWVRKbFl6Z3daamszWmpJNFlUSXlZamRpT1RjM1l6UTFZVEF4WXpneU9ISC9oN3Uxd21NYk1Ca1cKQkhkdFlXTUVFV013T21Rd09qRXlPbUkxT2pKaU9qZzIvNGVibGRKa09qQTRGZ1J6Wldsa0JEQXdOREk0TWtaRgpNelEyTTBVNE1EQXhOak15TURFeU56WXlNamt6T1RrNU56WkRRVVpHTkRrME5USTNSRVUyTVRFd01nWUtLb1pJCmh2ZGpaQVlCRHdRa01TTC9oT3FGbkZBS01BZ1dCRTFCVGxBeEFQK0Urb21VVUFvd0NCWUVUMEpLVURFQU1CSUcKQ1NxR1NJYjNZMlFLQWdRRk1BTUNBUUF3SndZSktvWklodmRqWkFnSEJBbE1MU2w5aG9ZeTE3dVFld0IKZ1pqc2hZeitkemlXU2I4U2tRQzdFZEZZM0Z2bWswQXE3ZlVnY3JhcTZqU1g4MUZWcXc1bjNpRlQwc0NRSXhibgpBQkVCQ1JZazlodFlML3RlZ0kzc29DeUZzcmM1TTg1OXhTcHRGNFh2ejU1UVZDQkw1OFdtSzZnVFNjVHlVSDN3CjJSVERXUjNGRnJxR2Y3aTVCV1lxRVdLMEkzNFgyTWJsZnR4OTM3bmI3SysrTFVkYk81YnFZaDM0bTREcUZwbCsKZkRnaDVtdU1DNkVlWWZPeTlpdEJsbE5ad2VlUWJBUmtKa2FHUGJ5aEdpYlNCcTZzR0NrQVJ2WTltT2ZNT3hZYgplWitlNnhBRmZ4MjFwUk9BM0xZc0FmMzBycmtRc0tKODVBRHZVMzFKdUFibnpmeGQzRnorbHBXRi9FeHU5QVNtCm1XcFFTY1VZaXF5TXZHUWQ5Rnl6ZEtNYk1SQ1ExSWpGZVhOUWhWQTY0VzY4M0czbldzRjR3a3lFRHl5RnI1N2QKcUJ3dFA4djRhSXh4ZHVSODVaT0lScWs0UGlnVlUvbVRpVUVQem16Wlh2MVB3ZzNlOGpjL3pZODZoYWZHaDZsZApMbHAyTU9uakNuN1pmKzFFN0RpcTNrS280bVo0MHY0cEJOV1BodnZGZ0R5WDdSLy9UaTBvbCtnbzc1QmR2b1NpCmljckUzYUdOc0hhb0d6cE90SHVOdW5HNTh3UW9BWXMwSUhQOGNvdmxPMDhHWHVRUlh1NVYyM1VyK2ZLQ2t5dm8KSEptYWVmL29ZbmR3QzAvK1pUL2FOeTZKUUEzUzg1Y3dzaFE3YXpYajlZazNndzkzcE0xN3I5dExGejNHWDRQegoyZWhMclVOTCtZcSs1bW1zeTF6c2RlcENGMldkR09KbThnajluMjdHUDNVVnhUOVA4TkI0K1YwNzlEWXd6TEdiCjhLdGZCRExSM2cwSXppYkZQNzZ5VC9FTDUwYmlacU41SlNLYnoxS2lZSGlGS05CYnJEbDlhWWFNdnFJNHhOblgKNVdpZk43WDk3UHE0TFQzYW5rcmhUZUVqeXFxeC9kYmovMGh6bG1RRCtMaW5UV29SU2ZFVWI2Ni9peHFFb3BrbQp3V2h6dXZPMUVPaTRseUJUV09MdmxUY1h1WUpwTUpRZHNCb0dkSVdrbm80Qnp5N3BESXMvSXpNUVEzaUpEYVc3CnBiTldrSUNTdytEVWJPdDVXZFZqN0FHTEFUR2FVRW1ZS1dZNnByclo2bks4S1lReFJDN3NvdDc2SHJaajJlVnoKRVl4cm1hVy9lRHhuYVhDOGxCNXpCS0wrQ1pDVmZhWHlEdmV1MGQvdzhpNGNnRTVqSkF6S2FFcmtDeUlaSm5KdApYTkJhOEl3M3Y3aWGNlhPREFEaU9KK3hGTjdJQXlzem5YMEw4RFJ6Mkc1d2I5clllMW03eDRHM3duaklxZG1hCm9DdzZINnNPcFFRM2RWcVd0UDhrL1FJbk5ONnV2dVhEN3kvblVsdlVqcnlVbENlcFlzeDhkOFNScWw1M3d0SGwKYWxabUpvRWh0QTdRVDBUZHVVUmJ6M2dabWVXKzJRM3BlazVHaVBKRStkci83YklHRGxhdWZJVkVQTXc4clg3agpVNTVRWmZ6MHZyc3p5eGg3U0x1SDc3RmVGd3ljVlJId0t6NkFndlpOb0R2b0dMWk9KTi82V1NxVlhmczYxUEdPCmN0d29WVkkzejhYMGtWUXRHeUpjQTlFYjN0SFBHMzMrM1RpYnBsL2R0VW1LRU5WeUUrQTJUZDN5RFRydVBFQmsKZHJhM3pFc25ZWXFxR2I3aVhvMVB6Y3crUGo5QTRpQlE2cTl3RGtBbEFDdTZsZnUwCi0tLS0tRU5EIENPTlRBSU5FUi0tLS0tCg==';
    }
    private function generateWildcardTicket(): string { /* ... same as activator.php ... */
        $ticketContent = json_encode(['UniqueDeviceID' => $this->deviceInfo['UniqueDeviceID'] ?? '0', 'ActivationRandomness' => $this->deviceInfo['ActivationRandomness'] ?? '0', 'timestamp' => time()]);
        $dataFile = tempnam(sys_get_temp_dir(), 'wdt_data'); $signedFile = tempnam(sys_get_temp_dir(), 'wdt_signed');
        if ($dataFile === false || $signedFile === false) throw new \RuntimeException("Failed to create temporary files for WildcardTicket signing.");
        try {
            file_put_contents($dataFile, $ticketContent);
            $success = openssl_pkcs7_sign($dataFile, $signedFile, $this->serverCertificate, $this->serverPrivateKey, [], PKCS7_BINARY);
            if (!$success) throw new \RuntimeException("Failed to sign WildcardTicket data.");
            $signedData = file_get_contents($signedFile);
            if ($signedData === false) throw new \RuntimeException("Failed to read signed WildcardTicket data.");
        } finally { unlink($dataFile); unlink($signedFile); }
        return base64_encode($signedData);
    }
    private function generateAccountToken(string $wildcardTicket): string { /* ... same as activator.php, ensure all deviceInfo keys are checked or have defaults ... */
        $tokenData = [
            'InternationalMobileEquipmentIdentity' => $this->deviceInfo['InternationalMobileEquipmentIdentity'] ?? '',
            'ActivationTicket' => $wildcardTicket, // This was previously a static string, now using the generated one.
            'PhoneNumberNotificationURL' => 'https://albert.apple.com/deviceservices/phoneHome',
            'InternationalMobileSubscriberIdentity' => $this->deviceInfo['InternationalMobileSubscriberIdentity'] ?? '',
            'ProductType' => $this->deviceInfo['ProductType'] ?? 'iPhone0,0',
            'UniqueDeviceID' => $this->deviceInfo['UniqueDeviceID'] ?? '0',
            'SerialNumber' => $this->deviceInfo['SerialNumber'] ?? 'C00000000000',
            'MobileEquipmentIdentifier' => $this->deviceInfo['MobileEquipmentIdentifier'] ?? '',
            'InternationalMobileEquipmentIdentity2' => $this->deviceInfo['InternationalMobileEquipmentIdentity2'] ?? '',
            'PostponementInfo' => new \stdClass(),
            'ActivationRandomness' => $this->deviceInfo['ActivationRandomness'] ?? '0',
            'ActivityURL' => 'https://albert.apple.com/deviceservices/activity',
            'IntegratedCircuitCardIdentity' => $this->deviceInfo['IntegratedCircuitCardIdentity'] ?? '',
        ];
        $tokenString = "{\n";
        foreach ($tokenData as $key => $value) {
            if ($key === 'PostponementInfo') $tokenString .= "\t\"{$key}\" = {};\n";
            else $tokenString .= "\t\"{$key}\" = \"{$value}\";\n";
        }
        $tokenString .= "}";
        return $tokenString;
    }
    private function signData(string $data): string { /* ... same as activator.php ... */
        $signature = '';
        $success = openssl_sign($data, $signature, $this->serverPrivateKey, OPENSSL_ALGO_SHA256);
        if (!$success) throw new \RuntimeException("Failed to sign data for AccountTokenSignature.");
        return base64_encode($signature);
    }
    private function assembleActivationRecord(array $components): string { /* ... same as activator.php ... */
        $doc = new DOMDocument('1.0', 'UTF-8'); $doc->standalone = true; $doc->formatOutput = true;
        $doctype = new DOMDocumentType('plist', '-//Apple//DTD PLIST 1.0//EN', 'http://www.apple.com/DTDs/PropertyList-1.0.dtd');
        $doc->appendChild($doctype); $plist = $doc->createElement('plist'); $plist->setAttribute('version', '1.0');
        $doc->appendChild($plist); $rootDict = $doc->createElement('dict'); $plist->appendChild($rootDict);
        $rootDict->appendChild($doc->createElement('key', 'ActivationRecord'));
        $activationRecordDict = $doc->createElement('dict'); $rootDict->appendChild($activationRecordDict);
        foreach ($components as $key => $value) {
            $activationRecordDict->appendChild($doc->createElement('key', $key));
            if (is_bool($value)) $activationRecordDict->appendChild($doc->createElement($value ? 'true' : 'false'));
            elseif (is_string($value)) $activationRecordDict->appendChild($doc->createElement('data', $value));
        }
        $xml = $doc->saveXML(); if ($xml === false) throw new \RuntimeException("Failed to save final XML plist.");
        return $xml;
    }
}


// --- Database Functions ---
define('DB_FILE', __DIR__ . '/activation_simulator.sqlite');

function get_db_connection(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO('sqlite:' . DB_FILE);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("DB Connection Error: " . $e->getMessage());
            // Output a user-friendly error if DB connection fails critically
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Database connection failed. Check server logs.']);
            exit;
        }
    }
    return $pdo;
}

function init_db(): void
{
    $pdo = get_db_connection();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS devices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            udid TEXT UNIQUE NOT NULL,
            serial_number TEXT UNIQUE,
            imei TEXT,
            product_type TEXT,
            is_simulated_locked INTEGER NOT NULL DEFAULT 0,
            simulated_lock_message TEXT,
            activation_record_xml TEXT,
            notes TEXT,
            first_seen_timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_activation_attempt_timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
     // Add indexes for frequently queried columns
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_devices_udid ON devices (udid)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_devices_serial_number ON devices (serial_number)");
}


// Main script execution starts here

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    init_db(); // Ensure DB and table exist on POST request
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $finalRequestPlist = null;
    $activationInfoXmlString = null;

    if (stripos($contentType, 'multipart/form-data') !== false) {
        if (isset($_POST['activation-info'])) {
            $activationInfoXmlString = $_POST['activation-info'];
        } else {
            http_response_code(400); header('Content-Type: application/json');
            echo json_encode(['error' => 'Multipart request received, but "activation-info" part is missing.']);
            exit;
        }
    } elseif (stripos($contentType, 'application/xml') !== false || stripos($contentType, 'text/xml') !== false) {
        $finalRequestPlist = file_get_contents('php://input');
        if ($finalRequestPlist === false || empty($finalRequestPlist)) {
            http_response_code(400); header('Content-Type: application/json');
            echo json_encode(['error' => 'No raw XML POST data received.']);
            exit;
        }
    } else {
        $finalRequestPlist = file_get_contents('php://input');
        if ($finalRequestPlist === false || empty($finalRequestPlist)) {
            http_response_code(400); header('Content-Type: application/json');
            echo json_encode(['error' => 'Unsupported Content-Type or no data received.']);
            exit;
        }
    }

    if (isset($activationInfoXmlString)) {
        try {
            $xml = @simplexml_load_string($activationInfoXmlString);
            if ($xml === false) throw new \RuntimeException('Failed to parse XML from activation-info part.');

            $activationInfoXMLBase64 = null;
            if (isset($xml->dict)) { // Standard plist structure
                 for ($i = 0; $i < count($xml->dict->key); $i++) {
                    if ((string)$xml->dict->key[$i] === 'ActivationInfoXML') {
                        if (isset($xml->dict->data[$i])) {
                            $activationInfoXMLBase64 = (string)$xml->dict->data[$i];
                            break;
                        }
                    }
                }
            }

            if ($activationInfoXMLBase64 === null) throw new \RuntimeException('Could not find ActivationInfoXML <data> tag in activation-info part.');

            $decodedPlist = base64_decode($activationInfoXMLBase64, true);
            if ($decodedPlist === false || empty($decodedPlist)) throw new \RuntimeException('Failed to Base64-decode ActivationInfoXML or content is empty.');
            $finalRequestPlist = $decodedPlist;

        } catch (\RuntimeException $e) {
            http_response_code(400); header('Content-Type: application/json');
            error_log("Error processing activation-info: " . $e->getMessage());
            echo json_encode(['error' => 'Error processing activation-info part: ' . $e->getMessage()]);
            exit;
        }
    }

    // At this point, $finalRequestPlist should contain the actual device activation request plist string.
    // Now, interact with the database before deciding to call ActivationGenerator.

    if ($finalRequestPlist) {
        $parsedDeviceIdentifiers = [];
        try {
            // Temporarily parse to get UDID/SN for DB lookup before full ActivationGenerator instantiation
            $tempXml = @simplexml_load_string($finalRequestPlist);
            if ($tempXml === false || !isset($tempXml->dict)) {
                throw new \RuntimeException("Failed to parse the final request plist for identifiers.");
            }
            $tempDeviceInfo = [];
            $dict = $tempXml->dict;
            $count = count($dict->key);
            for ($i = 0; $i < $count; $i++) {
                $keyNode = $dict->key[$i];
                $valueNode = $dict->children()[$i * 2 + 1];
                $key = (string)$keyNode;
                 // Simplified parsing just for identifiers
                if (in_array($key, ['UniqueDeviceID', 'SerialNumber', 'ProductType', 'InternationalMobileEquipmentIdentity'])) {
                     $tempDeviceInfo[$key] = (string)$valueNode;
                } elseif ($key === 'DeviceInfo' || $key === 'DeviceID') { // Check common sub-dicts
                    $subDict = $valueNode;
                    if (isset($subDict->key)) {
                        $subCount = count($subDict->key);
                        for ($j = 0; $j < $subCount; $j++) {
                            $subKeyNode = $subDict->key[$j];
                            $subValueNode = $subDict->children()[$j * 2 + 1];
                            $subKey = (string)$subKeyNode;
                            if (in_array($subKey, ['UniqueDeviceID', 'SerialNumber', 'ProductType', 'InternationalMobileEquipmentIdentity'])) {
                                $tempDeviceInfo[$subKey] = (string)$subValueNode;
                            }
                        }
                    }
                }
            }
            $parsedDeviceIdentifiers['udid'] = $tempDeviceInfo['UniqueDeviceID'] ?? null;
            $parsedDeviceIdentifiers['serial_number'] = $tempDeviceInfo['SerialNumber'] ?? null;
            $parsedDeviceIdentifiers['imei'] = $tempDeviceInfo['InternationalMobileEquipmentIdentity'] ?? null;
            $parsedDeviceIdentifiers['product_type'] = $tempDeviceInfo['ProductType'] ?? null;

            if (empty($parsedDeviceIdentifiers['udid'])) {
                 throw new \RuntimeException("UniqueDeviceID could not be parsed from the request for DB lookup.");
            }

        } catch (\Exception $e) {
            http_response_code(400); header('Content-Type: application/json');
            error_log("Plist parsing for DB identifiers failed: " . $e->getMessage());
            echo json_encode(['error' => 'Critical error parsing request for device identifiers. ' . $e->getMessage()]);
            exit;
        }

        // Database interaction
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("SELECT * FROM devices WHERE udid = :udid");
        $stmt->execute(['udid' => $parsedDeviceIdentifiers['udid']]);
        $deviceRecord = $stmt->fetch();

        $currentTime = date('Y-m-d H:i:s');

        if (!$deviceRecord) {
            $stmt = $pdo->prepare("
                INSERT INTO devices (udid, serial_number, imei, product_type, last_activation_attempt_timestamp)
                VALUES (:udid, :serial_number, :imei, :product_type, :now)
            ");
            $stmt->execute([
                'udid' => $parsedDeviceIdentifiers['udid'],
                'serial_number' => $parsedDeviceIdentifiers['serial_number'],
                'imei' => $parsedDeviceIdentifiers['imei'],
                'product_type' => $parsedDeviceIdentifiers['product_type'],
                'now' => $currentTime
            ]);
            // Refetch to get the default values (like is_simulated_locked=0)
            $stmt = $pdo->prepare("SELECT * FROM devices WHERE udid = :udid");
            $stmt->execute(['udid' => $parsedDeviceIdentifiers['udid']]);
            $deviceRecord = $stmt->fetch();
        } else {
            $stmt = $pdo->prepare("UPDATE devices SET last_activation_attempt_timestamp = :now WHERE udid = :udid");
            $stmt->execute(['now' => $currentTime, 'udid' => $parsedDeviceIdentifiers['udid']]);
        }

        // Check simulated lock status
        if ($deviceRecord && $deviceRecord['is_simulated_locked'] == 1) {
            header('Content-Type: text/html; charset=utf-8');
            $lockMessage = htmlspecialchars($deviceRecord['simulated_lock_message'] ?? 'This device is SIMULATED as locked. For educational purposes only.');
            // Output a simple HTML page mimicking a lock screen message
            echo <<<HTML
<!DOCTYPE html>
<html>
<head><title>Simulated Activation Lock</title>
<style>body{font-family: Arial, sans-serif; text-align: center; padding-top: 50px;} .lock-icon{font-size: 50px;} .message{margin-top:20px; font-size:18px;}</style>
</head>
<body>
    <div class="lock-icon">⚠️</div>
    <h1>Activation Lock Simulation</h1>
    <div class="message">{$lockMessage}</div>
    <p><small>This is a local simulation and does not reflect real Apple Activation Lock status.</small></p>
</body>
</html>
HTML;
            exit;
        }

        // If not locked, or if it's a new device (default unlocked), proceed to generate activation record
        // Check if we have a stored activation record
        $activationRecordPlist = null;
        if ($deviceRecord && !empty($deviceRecord['activation_record_xml']) && $deviceRecord['is_simulated_locked'] == 0) {
            // Potentially add logic here to check if stored record is too old or if device details changed.
            // For now, just use it if it exists and device is not locked.
            // error_log("Using stored activation record for UDID: " . $parsedDeviceIdentifiers['udid']);
            // $activationRecordPlist = $deviceRecord['activation_record_xml'];
            // On second thought, for this educational purpose, let's always regenerate to show the process,
            // unless a specific feature to cache/reuse is implemented with more checks.
            // The 'activation_record_xml' column can be used by an admin tool to view what was generated.
        }

        // Always generate for now if not locked.
        try {
            $generator = new ActivationGenerator($finalRequestPlist);
            $activationRecordPlist = $generator->generate();

            // Store the generated record (optional, could be done only on first successful activation)
            if ($deviceRecord) { // Only if device was found or just inserted
                $stmt = $pdo->prepare("UPDATE devices SET activation_record_xml = :xml WHERE udid = :udid AND is_simulated_locked = 0");
                $stmt->execute(['xml' => $activationRecordPlist, 'udid' => $parsedDeviceIdentifiers['udid']]);
            }

        } catch (\RuntimeException $e) {
            http_response_code(500); header('Content-Type: application/json');
            error_log("Activation Generation Error: " . $e->getMessage() . " (UDID: " . ($parsedDeviceIdentifiers['udid'] ?? 'N/A') . ")");
            echo json_encode(['error' => 'Failed to generate activation record. Detail: ' . $e->getMessage()]);
            exit;
        }

        // Output as HTML (iTunes style)
        $htmlOutput = <<<HTML
<!DOCTYPE html>
<html>
   <head>
      <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
      <meta name="keywords" content="iTunes Store" />
      <meta name="description" content="iTunes Store" />
      <title>iPhone Activation</title>
      <link href="https://static.deviceservices.apple.com/deviceservices/stylesheets/common-min.css" charset="utf-8" rel="stylesheet" />
      <link href="https://static.deviceservices.apple.com/deviceservices/stylesheets/styles.css" charset="utf-8" rel="stylesheet" />
      <link href="https://static.deviceservices.apple.com/deviceservices/stylesheets/IPAJingleEndPointErrorPage-min.css" charset="utf-8" rel="stylesheet" />
      <script id="protocol" type="text/x-apple-plist">{$activationRecordPlist}</script>
      <script>
            var protocolElement = document.getElementById("protocol");
            var protocolContent = protocolElement.innerText;
            if (window.iTunes) { // Check if iTunes object exists
                iTunes.addProtocol(protocolContent);
            } else if (window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.iTunes) { // For newer macOS interaction
                 window.webkit.messageHandlers.iTunes.postMessage(protocolContent);
            } else {
                console.warn("iTunes protocol handler not found. Plist content:", protocolContent);
            }
      </script>
   </head>
   <body>
   </body>
</html>
HTML;
        header('Content-Type: text/html; charset=utf-8');
        echo $htmlOutput;
        exit;

    } else {
        http_response_code(400); header('Content-Type: application/json');
        echo json_encode(['error' => 'No valid activation request data could be processed.']);
        exit;
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Basic GET handler, e.g., to show status or link to admin script
    header('Content-Type: text/html');
    echo "<p>activator2.0.php is active. Expects POST for activation. For educational simulation only.</p>";
    echo "<p><a href='manage_lock.php'>Manage Simulated Device Locks (Admin)</a></p>"; // Link to potential admin script
} else {
    http_response_code(405); header('Content-Type: application/json');
    echo json_encode(['error' => 'Only POST and GET requests are allowed.']);
}

?>
