<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Response;
use App\Models\Integration;
use Carbon\Carbon;
use Schema;

class Hubspot
{
    private $expired = true;

    private $apiKey;

    const VERSION = 'v1';

    public function __construct()
    {
        $this->apiKey = config('hubspot.hapikey');

        //Check if apikey from integration table then use it.
        if (empty($this->apiKey)) {
            if (Schema::hasTable("integrations")){
                $hubspot = Integration::where('type', 'hubspot')->first();

                if ($hubspot) {
                    $this->apiKey = $hubspot->api_key;
                }
            }

        }
    }

    private function getClient()
    {
        $client = new Client([
            'base_uri' => config('hubspot.base_url'),
            'timeout'  => 50,
        ]);

        return $client;
    }

    private function send($path, $options = [], $method = 'GET')
    {
        $client = $this->getClient();
        $defaultOptions = [
            'headers' => [
                'Content-Type'  => 'application/json',
            ],
        ];

        try {
            $response = $client->request($method, $path, array_merge($defaultOptions, $options));
            return json_decode($response->getBody()->getContents());
        } catch (ClientException $e) {
            \Log::debug($e->getMessage());
            if ($e->hasResponse()) {
                $exception = $e->getResponse();
                return json_decode($exception->getBody()->getContents());
            }
        } catch (\Exception $e) {
            \Log::debug($e->getMessage());
        }
    }

    public function getData($path)
    {
        return $this->send($path);
    }

    public function updateData($path, $data = null)
    {
        $option = [];
        if (!empty($data)) {
            $option = [
                'body' => json_encode($data),
            ];
        }

        return $this->send($path, $option, 'PUT');
    }

    public function deleteData($path, $data = null)
    {
        $option = [];
        if (!empty($data)) {
            $option = [
                'body' => json_encode($data),
            ];
        }
        return $this->send($path, $option, 'DELETE');
    }

    public function saveData($path, $data = null)
    {
        $option = [];
        if (!empty($data)) {
            $option = [
                'body' => json_encode($data),
            ];
        }



        return $this->send($path, $option, 'POST');
    }

    public function sendEmail($data){
        $api = '/email/public/v1/singleEmail/send?hapikey=%s';
        return $this->saveData(sprintf($api, $this->apiKey), $data);
    }

    public function sendSmtpEmail($data){
        $api = '/email/public/v1/smtpapi/tokens?hapikey=%s';
        return $this->saveData(sprintf($api, $this->apiKey), $data);
    }

    public function getAllContacts($offset = null, $count = 250)
    {
        $api = '/contacts/%s/lists/all/contacts/all?hapikey=%s&count=%s';
        if ($offset) {
            $api .= '&vidOffset='. $offset;
        }

        return $this->getData(sprintf($api, self::VERSION, $this->apiKey, $count));
    }

    public function createContact($data){
        $api = '/contacts/v1/contact?hapikey=%s';
        return $this->saveData(sprintf($api, $this->apiKey), $data);
    }

    public function updateContact($vid, $data){
        $api = '/contacts/v1/contact/vid/%s/profile?hapikey=%s';
        return $this->saveData(sprintf($api, $vid, $this->apiKey), $data);
    }

    public function deleteContact($vid){
        $api = '/contacts/v1/contact/vid/%s?hapikey=%s';
        return $this->deleteData(sprintf($api, $vid, $this->apiKey));
    }

    public function getContact($vid){
        $api = '/contacts/v1/contact/vid/%s/profile/?hapikey=%s';
        return $this->getData(sprintf($api, $vid, $this->apiKey));
    }

    public function getWorkflows(){
        $api = '/automation/v3/workflows/?hapikey=%s';
        return $this->getData(sprintf($api, $this->apiKey));
    }

    public function enrollContactWorkflow($workflowId, $email){
        $api = '/automation/v2/workflows/%s/enrollments/contacts/%s?hapikey=%s';
        return $this->saveData(sprintf($api, $workflowId, $email, $this->apiKey));
    }

    /**
    * Create an engagement (a note, task, or activity) on an object.
    *
    * @param  array $data
    * @param  string $type
    * @return object
    */
    public function addEngagements(array $data = [], string $type = 'NOTE')
    {
        $engagement = $this->bindEngagement($type);
        $body       = array_replace([], $engagement, $data);

        $api = '/engagements/%s/engagements?hapikey=%s';
        return $this->saveData(sprintf($api, self::VERSION, $this->apiKey), $body);
    }

    /**
    * bind engagement data for contacts
    *
    * @param string $type
    * @return array
    */
    private function bindEngagement(string $type = 'NOTE')
    {
        $data = [
            'engagement' => [
                'type'      => $type,
                'timestamp' => Carbon::now()->timestamp
            ]
        ];

        return $data;
    }


    /**
    * get engagements tasks for contacts
    *
    * @return object
    */
    public function getTasks($offset = null, $limit = 5)
    {
        $api = '/engagements/%s/engagements/paged?hapikey=%s&limit=%s';
        if ($offset) {
            $api .= '&offset='. $offset;
        }

        return $this->getData(sprintf($api, self::VERSION, $this->apiKey, $limit));
    }

    public function updateTask($id, $data)
    {
        $api = '/engagements/%s/engagements/%s?hapikey=%s';
        $option = [];
        if (!empty($data)) {
            $option = [
                'body' => json_encode($data),
            ];
        }
        
        return $this->send(sprintf($api, self::VERSION, $id, $this->apiKey), $option, 'PATCH');        
    }    
}