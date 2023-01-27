<?php 
class MobilePay_JWT 
{
    private $asymmetric_key = "";
    private $vat_number = "";

    public function __construct($private_key_string, $vat_number)
    {
        $this->vat_number = $vat_number;
        $this->asymmetric_key = openssl_pkey_get_private($private_key_string);

        if(!$this->asymmetric_key) throw new Exception("Could not load private key");
    }

    private function to_base64($input) 
    {
        return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
    }

    private function get_payload()
    {
        $iat = time();
        return array(
            'exp' => $iat + 3600,
            'iat' => $iat,
            'sub' => $this->vat_number
        );
    }

    private function get_header()
    {
        return array(
            'typ' => 'jwt',
            'alg' => 'RS512'
        );
    }

    private function get_signature($signing_payload)
    {
        openssl_sign($signing_payload, $signature, $this->asymmetric_key, OPENSSL_ALGO_SHA512);

        return $signature;
    }
        
    public function get_token()
    {
        $signing_input = $this->to_base64( json_encode( $this->get_header() ) ) . "." . $this->to_base64( json_encode( $this->get_payload() ) );
        $signature = $this->to_base64( $this->get_signature($signing_input) );

        return $signing_input . "." . $signature;
    }
    
}

