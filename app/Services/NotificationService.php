<?php
namespace App\Services;

use App\Models\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use App\Notifications\EmailNotification;
use Exception;

class NotificationService
{
    public static function create($data)
    {
        try {
            $notification = Notification::create($data);
            return $notification;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public static function sendRefillNotificationOnProcessUpdate($customerProcess) {
        try {
            $processName = $customerProcess->master_process->title ?? 'N/A';
            $customerEmail = $customerProcess->customer->email ?? null;
            // send email to given customer emails
            $emailData = [
                'title' => 'Process Re-assigned',
                'body' => 'I hope this email finds you well. The '.$processName.' process that you have filled is modified by your tenant. Please re-fill the process form.The details of the process are as follows:',
                'action' => [
                    'title' => 'Click Here',
                    'url' => $customerProcess->process_link,
                ]
            ];
            NotificationFacade::route('mail', $customerEmail)->notify(new EmailNotification($emailData));
            $customerProcess->update(['is_notified' => '1']);
            return true;
        } catch(Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public static function sendRegistrationDetailMail($user, $details) {
        try {
            // send email to given customer emails
            $emailData = [
                'title' => 'Registration Successful',
                'body' => 'I hope this email finds you well. We are delighted to have you on board. Thank you for registering.',
                'lines' => [
                    '**Full Name    :** '.$user->first_name.' '.$user->last_name,
                    '**Email        :** '.$user->email,
                    '**Password     :** '.$details['password'],
                ],
                'action' => [
                    'title' => 'Click Here To Login',
                    'url' => env('FRONT_URL', 'https://hi-benjie.netlify.app'),
                ]
            ];
            $user->notify(new EmailNotification($emailData));
            return true;
        } catch(Exception $e) {
            throw new Exception($e->getMessage());
        }
    }
    
    public static function sendProcessTerminationMailToCustomer($customerProcess) {
        try {
            // $processId = $customerProcess->master_process->uuid ?? 'N/A';
            $processName = $customerProcess->master_process->title ?? 'N/A';
            $customerEmail = $customerProcess->customer->email ?? null;
            // send email to given customer emails
            $emailData = [
                'title' => 'Process Terminated',
                'body' => 'I hope this email finds you well. The '.$processName.' process that assigned to you is deleted by your tenant.The details of the process are as follows:',
                'lines' => [
                    // '**Process Id :**',
                    // $processId,
                    '**Process Title :** '.$processName
                ]
            ];
            NotificationFacade::route('mail', $customerEmail)->notify(new EmailNotification($emailData));
            $customerProcess->update(['is_notified' => '1']);
            return true;
        } catch(Exception $e) {
            throw new Exception($e->getMessage());
        }
    }
}