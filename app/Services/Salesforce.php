<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Response;
use App\Models\Integration;
use Schema;

class Salesforce
{
    private $expired = true;
    private $token;
    private $config;

    const VERSION = 'v44.0';

    public function __construct()
    {
        $this->config = config('salesforce');
    }

    /**
     * Return a guzzle client based on the config endpoint.
     * @return \GuzzleHttp\Client
     */
    private function getClient()
    {
        return new Client([
            'base_uri' => $this->config['endpoint'],
            'timeout'  => 50,
        ]);
    }

    /**
     * Using the config data, request and set the token.
     */
    private function setToken()
    {
        if ($this->expired) {
            $client =  new Client([
                'base_uri' => $this->config['login'],
                'timeout'  => 50,
            ]);

            $params = [
                'form_params' => [
                    'grant_type'    => 'password',
                    'client_id'     => $this->config['clientId'],
                    'client_secret' => $this->config['clientSecret'],
                    'username'      => $this->config['username'],
                    'password'      => sprintf("%s%s", $this->config['password'], $this->config['securityToken'])
                ]
            ];

            $response = $client->request('POST', 'token', $params);
            $body  = json_decode($response->getBody()->getContents());

            $this->token = $body->access_token;
            $this->expired = false;
        }
    }

    private function getToken()
    {
        if ($this->expired || empty($this->token)) {
            $this->setToken();
        }

        return $this->token;
    }

    public function authorization($path, $options = [], $method = 'GET')
    {
        $client =  new Client([
            'base_uri' => $this->config['login'],
            'timeout'  => 50,
        ]);

        try {
            $response = $client->request($method, $path, $options);
            return json_decode($response->getBody()->getContents());
        } catch (ClientException $e) {
            if ($e->hasResponse()) {
                $exception = $e->getResponse();
                return json_decode($exception->getBody()->getContents());
            }
        } catch (\Exception $e) {
            print_r($e->getMessage());
            \Log::debug($e->getMessage());
        }

    }

    private function send($path, $options = [], $method = 'GET')
    {
        $integration = Integration::where('type', 'salesforce')->first();
        if($integration){
            $this->token = $integration->access_token;
        }

        if(empty($this->token)){
            $this->token  = $this->getToken();
        }

        $client = $this->getClient();

        $defaultOptions = [
            'headers' => [
                'Authorization' => sprintf("Bearer %s", $this->token),
                'Content-Type'  => 'application/json',
            ],
        ];

        try {
            $response = $client->request($method, $path, array_merge($defaultOptions, $options));
            return json_decode($response->getBody()->getContents());
        } catch (ClientException $e) {
            if ($e->hasResponse()) {
                $exception = $e->getResponse();
                return json_decode($exception->getBody()->getContents());
            }
        } catch (\Exception $e) {
            print_r($e->getMessage());
            \Log::debug($e->getMessage());
        }
    }

    public function getData($path)
    {
        return $this->send($path);
    }

    public function updateData($path, $data = null, $sendType = 'json', $method = 'PUT')
    {
        $option = [];
        if (!empty($data)) {
            $option = [
                $sendType => $data,
            ];
        }

        return $this->send($path, $option, $method);
    }

    public function deleteData($path, $data = null, $sendType = 'json')
    {
        $option = [];
        if (!empty($data)) {
            $option = [
                $sendType => $data,
            ];
        }
        return $this->send($path, $option, 'DELETE');
    }

    public function saveData($path, $data = null, $sendType = 'json')
    {
        $option = [];
        if (!empty($data)) {
            $option = [
                $sendType => $data,
            ];
        }

        return $this->send($path, $option, 'POST');
    }

    /**
    * Get Version
    * @return mixed
    */
    public function get($entity='') {
        $api = '/services/data/%s';
        return $this->getData(sprintf($api, $entity));
    }

    /**
    * Create Entity
    * @param $entity
    * @param array $params
    * @return bool|string
    */
    public function create($entity, $params = []) {
        $api = '/services/data/%s/sobjects/%s';
        return $this->saveData(sprintf($api, self::VERSION, $entity), $params);
    }

    /**
    * Update Entity
    * @param $entity
    * @param $id
    * @param array $params
    * @return mixed
    */
    public function update($entity, $id, $params = []) {
        $api = '/services/data/%s/sobjects/%s%s';
        return $this->updateData(sprintf($api, self::VERSION, $entity, $id), $params, 'json', 'PATCH');
    }

    public function search($entity, $field = 'Email', $val = '', $op = '&')
    {
        $api = '/services/data/%s/parameterizedSearch/?q=%s&sobject=%s%s%s.fields=%s';
        return $this->getData(sprintf($api, self::VERSION, $val, $entity, $op, $entity, $field));
    }

    /**
    * Get Entity object using ID
    * @param $entity
    * @param $id
    * @return object
    */
    public function getById($entity, $id)
    {
        $api = '/services/data/%s/sobjects/%s%s';
        return $this->getData(sprintf($api, self::VERSION, $entity, $id));
    }


    public function getAccessToken($request)
    {
        $params = [
            'form_params' => [
                'grant_type'    => 'authorization_code',
                'client_id'     => $request['client_id'],
                'client_secret' => $request['client_secret'],
                'redirect_uri'  => $request['callback'],
                'code'          => $request['code']
            ]
        ];

        return $this->authorization(sprintf('token'), $params, 'POST');
    }


    /**
    * [refresh token for ring central api and update Integration record]
    * @param  $id [integration model id]
    * @return object
    */
    public function refreshAccessToken($request)
    {
        $params = [
            'form_params' => [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $request['refresh_token'],
                'client_id'     => $request['client_id'],
                'client_secret' => $request['client_secret']
            ]
        ];

        return $this->authorization(sprintf('token'), $params, 'POST');
    }

    /**
    * Get All Records for $entity
    * @param $entity
    * @param $limit
    * @param $offset
    * @param $listviewsID
    * @return object
    */
    public function getRecords($limit = 25, $offset = 0, $entity = 'Contact', $listviewsID = '')
    {
        $api = '/services/data/%s/sobjects/%s/listviews/%s/results?limit=%s&offset=%s';
        return $this->getData(sprintf($api, self::VERSION, $entity, $listviewsID, $limit, $offset));
    }

    /**
    * Count Entity
    * @param $entity
    * @return object
    */
    public function countRecords($entity)
    {
        $api = '/services/data/%s/query?q=SELECT COUNT() FROM %s';
        return $this->getData(sprintf($api, self::VERSION, $entity));
    }

    /**
    * Get listviews for entity
    * @param $entity
    * @return object
    */
    public function listviews(string $entity = 'Account')
    {
        $api = '/services/data/%s/sobjects/%s/listviews';
        return $this->getData(sprintf($api, self::VERSION, $entity));
    }

    /**
    * Get contacts by account
    * @param $entity
    * @param $query
    * @return object
    */
    public function getContactByAccount($entity, $query)
    {
        return $this->getCustomQuery($entity, $query);
    }
    
    /**
     * Search a salesforce instance with the given search term.
     * @param string $search_term
     * @return object
     */
    public function searchQuery(string $search_term)
    {
        $api = '/services/data/%s/search/?q=FIND {' . $search_term . '}';
        return $this->getData(sprintf($api, self::VERSION));
    }
    
    /**
     * Return a list of updated/created records for the given query.
     * @param string $entity
     * @param string $query [?start={date}&end={date}]
     */
    public function getUpdatedRecords(string $entity, string $query)
    {
        $api = '/services/data/%s/sobjects/%s/updated' . $query;
        return $this->getData(sprintf($api, self::VERSION, $entity));
    }

    /**
     * Return a custom query result.
     * @param string $entity
     * @param string $query
     * @return object
     */
    public function getCustomQuery($entity, $query)
    {
        $api = '/services/data/%s/query?q='.$query;
        return $this->getData(sprintf($api, self::VERSION, $entity));
    }

    public function getRecordTypes(){
        $api = '/services/data/v43.0/tooling/sobjects/RecordType/defaultValues?recordTypeId&fields';
        return $this->getData(sprintf($api, self::VERSION));
    }

    public function getCustomFields($entity)
    {
        $api = '/services/data/%s/sobjects/%s/describe';
        return $this->getData(sprintf($api, self::VERSION, $entity));
    }
}