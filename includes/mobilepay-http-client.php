<?php

class MobilePay_HttpClient
{
    private $base_url = "https://wp7586.danskenet.net/integrator/";
    private $access_token = "";

    public function __construct($access_token)
    {
        $this->access_token = $access_token;
    }

    public function create_payment($payment)
    {
        return $this->post("payment", $payment);
    }

    private function post($path, $request)
    {
        $response = array();
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->base_url.$path);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request));

        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "Accept: application/json",
            sprintf("Authorization: Bearer %s", $this->access_token),
        ));

        $curl_result = curl_exec($ch);

        if(curl_errno($ch))
        {
            $response = array(
                'status' => 'error',
                'error' => curl_error($ch),
                'status_code' => curl_getinfo($ch, CURLINFO_HTTP_CODE)
            );
        }
        else
        {
            $response = array(
                'status' => 'success',
                'response' => json_decode($curl_result)
            );
        }

        curl_close($ch);

        return $response;
    }
}
