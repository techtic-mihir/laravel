<?php

namespace App\Services;

use App\Models\User;
use App\Models\Integration;
use App\Models\InstanceSettings;

use Response;
use Carbon\Carbon;
use Helper;

class Google
{
    public $user      = 'me';
    public $threads   = array();
    public $opt_param = array();
    public $pageToken = null;
    public $client;
    public $service;

    public function __construct()
    {
        $this->config  = config('google');
        $this->client  = new \Google_Client($this->config);
        $this->service = new \Google_Service_Gmail($this->client);
    }

    public function addGmailAccount($data, $user_id)
    {
        $saveData                     = [];
        $saveData['user_id']          = $user_id;
        $saveData['type']             = 'google';
        $saveData['auth_url']         = $data['auth_url'];
        $saveData['access_token_url'] = $data['access_token_url'];
        $saveData['client_id']        = $data['client_id'];
        $saveData['client_secret']    = $data['client_secret'];
        $saveData['callback']         = $data['callback'];
        $saveData['active']           = 0;
        $data                         = Integration::updateOrCreate(['id' => $data['id']], $saveData);

        $this->setConstant('CREDENTIALS_PATH', $saveData);
        $client = $this->getClient('all', $data);

        return $client;

    }

    public function setConstant($name, $value)
    {
        if (!defined($name)) {
            define($name, $value);
        }
        return true;
    }

    public function getClient($type = '', $credentials)
    {
        try {
            $credentials = $this->setCredential($credentials);

            $this->client->setApplicationName('Gmail API PHP Quickstart');
            $this->client->setScopes([\Google_Service_Gmail::GMAIL_READONLY, \Google_Service_Gmail::GMAIL_COMPOSE, \Google_Service_Calendar::CALENDAR]);
            $this->client->setAuthConfig($credentials);
            $this->client->setAccessType('offline');
            $this->client->setApprovalPrompt('force');
            $this->client->setState($credentials['id']);
            $authUrl = $this->client->createAuthUrl();
            return $authUrl;
        } catch (\Exception $e) {
            return [
                'code'     => 500,
                'message'  => $e->getMessage(),
                'response' => false,
            ];
        }
    }

    public function expandHomeDirectory($path)
    {
        $homeDirectory = config('benjamin.home');
        if (empty($homeDirectory)) {
            $homeDirectory = config('benjamin.homedrive') . config('benjamin.homepath');
        }
        return str_replace('~', realpath($homeDirectory), $path);
    }

    public function setCredential($data, $type = 'set')
    {
        $credentials = $data;
        if ($type == 'set') {
            $credentials['auth_uri']      = $data['auth_url'];
            $credentials['token_uri']     = $data['access_token_url'];
            $credentials['client_secret'] = $data['client_secret'];
            $credentials['redirect_uris'] = [$data['callback']];
        } else {
            unset($credentials['auth_uri']);
            unset($credentials['token_uri']);
            unset($credentials['client_secret']);
            unset($credentials['redirect_uris']);
        }

        return $credentials;
    }

    public function gmailAccountToken($post, $user_id)
    {
        $authCode = $post['code'];
        $id       = $post['state'];

        $integration = Integration::find($id);
        if (!$integration) {
            return false;
        }
        $credentials = $this->setCredential($integration);

        $this->client->setApplicationName('Gmail API PHP Quickstart');
        $this->client->setScopes([\Google_Service_Gmail::GMAIL_READONLY, \Google_Service_Gmail::GMAIL_COMPOSE, \Google_Service_Calendar::CALENDAR]);
        $this->client->setAuthConfig($credentials);
        $this->client->setAccessType('offline');
        $this->client->setApprovalPrompt('force');

        $accessToken = $this->client->fetchAccessTokenWithAuthCode($authCode);

        $this->setCredential($integration, 'unset');
        if (!isset($accessToken['error'])) {
            $save_data = ['access_token' => json_encode($accessToken), 'active' => 1];
            $integration->update($save_data);
        } else {
            return false;
        }

        return true;
    }

    public function getCalendarUsingCommand($email)
    {
        $user = User::where('email', $email)->first();
        if ($user) {
            $integration = Integration::where('user_id', $user->id)->where('type', 'google')->first();
            
            if ($integration) {
                
                if(!is_null($integration->calendar_id)){
                    $calendarId = $integration->calendar_id;
                } else {
                    $calendarId = 'primary';
                }

                $credentials = $this->setCredential($integration);
                $client = new \Google_Client();
                $client->setApplicationName('Google Calendar API PHP Quickstart');
                $client->setScopes(\Google_Service_Calendar::CALENDAR);
                $client->setAuthConfig($credentials);
                $client->setAccessType('offline');
                $client->setPrompt('select_account consent');
                $accessToken = json_decode($integration['access_token'], true);
                $client->setAccessToken($accessToken);

                $service = new \Google_Service_Calendar($client);
                // Print the next 10 events on the user's calendar.
                $optParams = array(
                  'maxResults' => 10,
                  'orderBy' => 'startTime',
                  'singleEvents' => true,
                  'timeMin' => date('c'),
                );
                $results = $service->events->listEvents($calendarId, $optParams);
                $events = $results->getItems();

                if (empty($events)) {
                    print "No upcoming events found.\n";
                } else {
                    print "Upcoming events:\n";
                    foreach ($events as $event) {
                        print_r($event);exit();
                        $start = $event->start->dateTime;
                        if (empty($start)) {
                            $start = $event->start->date;
                        }
                        printf("%s (%s)\n", $event->getSummary(), $start);
                    }
                }
            }
        }

        return [];
    }

    public function getGmailAccountUsingCommand($email)
    {
        $user = User::where('email', $email)->first();
        if ($user) {
            $integration = Integration::where('user_id', $user->id)->where('type', 'google')->first();

            if ($integration) {
                $this->setConstant('CREDENTIALS_PATH', $integration);
                $credentials = $this->setCredential($integration);
                $this->client->setApplicationName('Gmail API PHP Quickstart');
                $this->client->setScopes([\Google_Service_Gmail::GMAIL_READONLY, \Google_Service_Gmail::GMAIL_COMPOSE, \Google_Service_Calendar::CALENDAR]);
                $this->client->setAuthConfig($credentials);
                $this->client->setAccessType('offline');
                $this->client->setApprovalPrompt('force');
                $accessToken = json_decode($credentials['access_token'], true);
                $this->client->setAccessToken($accessToken);

                if ($this->client->isAccessTokenExpired()) {
                    $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
                    $integration = Integration::find($integration['id'])->update(['access_token' => json_encode($this->client->getAccessToken())]);
                }

                $client = $this->listThreads('unread');

                return $client;
            }

            return [];
        }

        return [];
    }

    public function listThreads($type)
    {
        $pageToken  = null;
        $maxResults = 25;

        if ($pageToken) {
            $this->opt_param['pageToken'] = $pageToken;
        }

        $this->opt_param['labelIds']         = 'INBOX';
        $this->opt_param['includeSpamTrash'] = 'false';
        if ($type == 'unread') {
            $this->opt_param['q'] = 'is:unread';
        }

        $this->opt_param['maxResults'] = $maxResults;
        $threadsResponse               = $this->service->users_threads->listUsersThreads($this->user, $this->opt_param);
        $threads                       = array();
        if ($threadsResponse->getThreads()) {
            $threads   = array_merge($threads, $threadsResponse->getThreads());
            $pageToken = $threadsResponse->getNextPageToken();
        }

        $messagearray = array();
        foreach ($threads as $thread) {
            $threadId = $thread->getId();
            $thread   = $this->service->users_threads->get($this->user, $threadId);
            $messages = $thread->getMessages();
            foreach ($messages as $message) {

                $id = $message->getId();

                foreach ($message->payload->headers as $key => $messagevalue) {
                    if ($messagevalue['name'] == 'From') {
                        $pattern = "/[\._a-zA-Z0-9-]+@[\._a-zA-Z0-9-]+/i";
                        preg_match_all($pattern, $messagevalue['value'], $matches);
                        $messagearray[$id]['email_from'] = $matches[0][0];
                    }if ($messagevalue['name'] == 'Date') {
                        $messagearray[$id]['email_date'] = date("d-m-Y H:i:s", strtotime($messagevalue['value']));
                    }if ($messagevalue['name'] == 'Subject') {
                        $messagearray[$id]['email_subject'] = $messagevalue['value'];
                    }
                }
                $messagearray[$id]['email_body'] = $message->snippet;
                $messagearray[$id]['status']     = 'unsorted';
                $messagearray[$id]['unique_id']  = $id;
            }
        }

        return array_values($messagearray);
    }

    public function sendEmail($userId, $info = [])
    {
        $integration = Integration::where('user_id', $userId)->where('type', 'google')->first();

        $this->setConstant('CREDENTIALS_PATH', $integration);
        $credentials = $this->setCredential($integration);
        $this->client->setApplicationName('Gmail API PHP Quickstart');
        $this->client->setScopes([\Google_Service_Gmail::GMAIL_READONLY, \Google_Service_Gmail::GMAIL_COMPOSE]);
        $this->client->setAuthConfig($credentials);
        $this->client->setAccessType('offline');
        $this->client->setApprovalPrompt('force');
        $accessToken = json_decode($credentials['access_token'], true);
        $this->client->setAccessToken($accessToken);
        if ($this->client->isAccessTokenExpired()) {
            $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
            $integration = Integration::find($integration['id'])->update(['access_token' => json_encode($this->client->getAccessToken())]);
        }

        if (!empty($info)) {
            $to         = implode(',', $info['to']);
            $strSubject = $info['subject'];
            $strRawBody = $info['body'];
        } else {
            $to         = 'hesom@getwela.com';
            $strSubject = 'Test mail using GMail API' . date('M d, Y h:i:s A');
            $strRawBody = 'this <b>is a</b> test!!';
        }

        $strRawMessage = "From: Benjamin AI <hesom@hhhsolutions.com>\r\n";
        $strRawMessage .= "To: " . $to . "\r\n";
        $strRawMessage .= 'Subject: =?utf-8?B?' . base64_encode($strSubject) . "?=\r\n";
        $strRawMessage .= "MIME-Version: 1.0\r\n";
        $strRawMessage .= "Content-Type: text/html; charset=utf-8\r\n";
        $strRawMessage .= 'Content-Transfer-Encoding: quoted-printable' . "\r\n\r\n";
        $strRawMessage .= $strRawBody . "\r\n";

        //Users.messages->send - Requires -> Prepare the message in message/rfc822

        // The message needs to be encoded in Base64URL
        $mime = rtrim(strtr(base64_encode($strRawMessage), '+/', '-_'), '=');
        $msg  = new \Google_Service_Gmail_Message();
        $msg->setRaw($mime);

        //The special value **me** can be used to indicate the authenticated user.
        $objSentMsg = $this->service->users_messages->send("me", $msg);

    }

    /**
    * get integrations settings
    * @param $name [integration like gmail, salesforce and wealthbox etc..]
    * @return object
    */
    public function getIntegrationSettings($name)
    {
        return IntegrationsSettings::where('integration', $name)->first();
    }

    /**
    * get gmail account events
    * @param $user
    * @param $begin [event start time]
    * @param $end [event end time]
    * @return array
    */

    public function getEvents($integration, $begin, $end, $timezone='Eastern Standard Time')
    {
        $events = [];
        $value  = $integration;

        if(!is_null($integration->calendar_id)){
            $calendarId = $integration->calendar_id;
        } else {
            $calendarId = 'primary';
        }

        $client = new \Google_Client($this->config);
        $client->setApplicationName('Benajmin Portal Google Event');
        $client->setScopes([\Google_Service_Gmail::GMAIL_READONLY, \Google_Service_Gmail::GMAIL_COMPOSE, \Google_Service_Calendar::CALENDAR]);
        $client->setAccessType('offline');
        $client->setApprovalPrompt('force');

        $this->setConstant('CREDENTIALS_PATH', $value);

        $credentials = $this->setCredential($value);
        $client->setAuthConfig($credentials);

        $accessToken = json_decode($credentials['access_token'], true);
        $client->setAccessToken($accessToken);

        if ($client->isAccessTokenExpired()) {
            try {
                $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                $value->access_token = json_encode($client->getAccessToken());
            } catch (LogicException $e) {
                $value->active = 0;
            }

            $this->setCredential($value, 'unset');
            $value->save();
        }

        $client->setScopes(\Google_Service_Calendar::CALENDAR);
        $service = new \Google_Service_Calendar($client);

        // get instance setting timezone
        $identifier = Helper::getStandardTimezones($timezone);
        $timezone   = $identifier['timezone'] ?? config('app.timezone');

        $optParams = array(
            'orderBy'      => 'startTime',
            'singleEvents' => true,
            'timeMin'      => $begin->toRfc3339String(),
            'timeMax'      => $end->toRfc3339String(),
            'timeZone'     => $timezone,
            'showDeleted'  => true
        );

        $results = $service->events->listEvents('primary', $optParams);
        $events  = $results->getItems();

        return $events;
    }

    /**
    * create and update gmail event
    * @param collection $integration
    * @param $begin [event start_time]
    * @param $end [event end_time]
    * @param $subject [event subject]
    * @param $body [event body]
    * @param $location [event location]
    * @param $attendees [event attendees]
    * @param $timeZone [event timeZone]
    * @param $meeting [householdMeeting object]
    * @return Google_Service_Calendar_Event object
    */
    public function createEvent($integration, $begin, $end, $subject, $body, $location = null, $attendees = [], $timeZone = 'America/New_York', $meeting= null )
    {
        $google = $integration;

        if(!is_null($google->calendar_id)){
            $calendarId = $google->calendar_id;
        } else {
            $calendarId = 'primary';
        }

        $client = new \Google_Client($this->config);
        $this->setConstant('CREDENTIALS_PATH', $google);
        $credentials = $this->setCredential($google);
        $client->setApplicationName('Gmail API PHP Quickstart');
        $client->setScopes([\Google_Service_Gmail::GMAIL_READONLY, \Google_Service_Gmail::GMAIL_COMPOSE, \Google_Service_Calendar::CALENDAR]);
        $client->setAuthConfig($credentials);
        $client->setAccessType('offline');
        $client->setApprovalPrompt('force');
        $accessToken = json_decode($credentials['access_token'], true);
        $client->setAccessToken($accessToken);
        if ($client->isAccessTokenExpired()) {
            try {
                $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                $google->access_token = json_encode($client->getAccessToken());
            } catch (LogicException $e) {
                $google->active = 0;
            }
            $this->setCredential($google, 'unset');
            $google->save();
        }

        $client->setScopes(\Google_Service_Calendar::CALENDAR);
        $service = new \Google_Service_Calendar($client);

        $identifier = Helper::getStandardTimezones($timeZone);
        $timezone   = $identifier['timezone'] ?? config('app.timezone');

        $data = [
            'summary'     => $subject,
            'description' => $body,
            'start' => [
                'dateTime' => Carbon::createFromFormat('Y-m-d H:i:s', $begin, $timezone)->toRfc3339String(),
                'timeZone' => $timezone
            ],
            'end' => [
                'dateTime' => Carbon::createFromFormat('Y-m-d H:i:s', $end, $timezone)->toRfc3339String(),
                'timeZone' => $timezone
            ],
            'reminders' => [
                'useDefault' => FALSE,
                'overrides'  => [
                    'method'  => 'email',
                    'minutes' => 30
                ]
            ]
        ];

        \Log::debug($data);

        if (!empty($location)){
            $data['location'] = $location;
        }

        if (!empty($attendees)){
            foreach ($attendees as $attendee) {
                $data['attendees'][] = ['email' => $attendee['address']];
            }
        }

        $event = new \Google_Service_Calendar_Event($data);
        if (isset($meeting->meeting_id) && !empty($meeting->meeting_id)) {
            $event = $service->events->update($calendarId, $meeting->meeting_id, $event, ['sendNotifications' => true]);
        }else{
            $event = $service->events->insert($calendarId, $event, ['sendNotifications' => true]);
        }

        return $event;
    }

    public function deleteEvent($google, $meetingId)
    {
        if(!is_null($google->calendar_id)){
            $calendarId = $google->calendar_id;
        } else {
            $calendarId = 'primary';
        }

        $client = new \Google_Client($this->config);
        $this->setConstant('CREDENTIALS_PATH', $google);
        $credentials = $this->setCredential($google);
        $client->setApplicationName('Gmail API PHP Quickstart');
        $client->setScopes([\Google_Service_Gmail::GMAIL_READONLY, \Google_Service_Gmail::GMAIL_COMPOSE, \Google_Service_Calendar::CALENDAR]);
        $client->setAuthConfig($credentials);
        $client->setAccessType('offline');
        $client->setApprovalPrompt('force');
        $accessToken = json_decode($credentials['access_token'], true);
        $client->setAccessToken($accessToken);

        if ($client->isAccessTokenExpired()) {
            try {
                $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                $google->access_token = json_encode($client->getAccessToken());
            } catch (LogicException $e) {
                $google->active = 0;
            }
            $this->setCredential($google, 'unset');
            $google->save();
        }

        $client->setScopes(\Google_Service_Calendar::CALENDAR);
        $service = new \Google_Service_Calendar($client);
        $event = $service->events->delete($calendarId, $meetingId);
        return $event;
    }
}