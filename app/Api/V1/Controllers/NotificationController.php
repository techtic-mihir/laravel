<?php
namespace App\Api\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Notifications\EmailNotification;
use Auth;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth as FacadesAuth;

class NotificationController extends Controller
{
    /**
     * index
     *
     * @param  mixed $request
     * @return void
     */
    public function index(Request $request)
    {
        try {
            $limit = $request->limit ?? 10;
            $sortby = ($request->sortby) ? $request->sortby : "title";
            $orderby = ($request->orderby == "true") ? "DESC" : "ASC";

            $query = Notification::query()->with(['fromUser', 'toUser']);

            if (!empty($request->search)) {
                $query->search($request->search);
            }

            $notifications = $query->orderBy($sortby, $orderby)->paginate($limit);

            return response()->json([
                'status_code' => 200,
                'data' => $notifications
            ], 200);
        } catch (Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * store
     *
     * @param  mixed $request
     * @return void
     */
    public function store(Request $request)
    {
        try {
            if ($request->read_all) {
                $userId = Auth::id();
                $notifications = Notification::where('to_user_id', $userId)->where('is_read', "0")->get();

                $notifications->each(function ($notification) {
                    $notification->update([
                        'is_read' => "1"
                    ]);
                });

                return response()->json([
                    'status_code' => 200,
                    'data' => [],
                    'message' => "All notifications have been read.",
                ]);
            } else if ($request->notification_id) {
                $userId = Auth::id();
                $notification = Notification::where('to_user_id', $userId)->where('id', $request->notification_id)->first();
                $notification->update(['is_read' => "1"]);

                return response()->json([
                    'status_code' => 200,
                    'data' => [],
                    'message' => "Notification has been read.",
                ]);
            } else {
                $data = $request->only(['title', 'body', 'from_user_id', 'to_user_id']);
                $notification = Notification::create($data);

                return response()->json([
                    'status_code' => 200,
                    'data' => $notification,
                    'message' => "Notification has been created successfully.",
                ]);
            }

        } catch (Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * update
     *
     * @param  mixed $request
     * @param  mixed $id
     * @return void
     */
    public function update(Request $request, $id)
    {
        try {
            $notification = Notification::where('id', $id)->first();

            if ($notification) {
                $details = ['title', 'body', 'from_user_id', 'to_user_id'];

                foreach ($details as $detail) {
                    if ($request->exists($detail)) {
                        switch ($detail) {
                            default:
                                $notification->$detail = $request->$detail;
                                break;
                        }
                    }
                }

                $notification->save();

                return response()->json([
                    'status_code' => 200,
                    'data' => $notification,
                ]);
            }

            return response()->json([
                'status_code' => 400,
                'message' => 'Invalid Notification.',
            ], 400);
        } catch (Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * delete
     *
     * @param  mixed $id
     * @return void
     */
    public function delete($id)
    {
        try {
            $notification = Notification::where('id', $id)->delete();

            if ($notification) {
                return response()->json([
                    'status_code' => 200,
                    'message' => 'Notification has been deleted successfully.'
                ]);
            }

            return response()->json([
                'status_code' => 400,
                'message' => 'Invalid Notification.',
            ], 400);
        } catch (Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * show
     *
     * @param  mixed $id
     * @return void
     */
    public function show($id)
    {
        try {
            $notification = Notification::with(['fromUser', 'toUser'])->where('id', $id)->first();

            if ($notification) {
                return response()->json([
                    'status_code' => 200,
                    'data' => $notification
                ], 200);
            }

            return response()->json([
                'status_code' => 400,
                'message' => 'Invalid Notification.',
            ], 400);
        } catch (Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * notificationTest
     *
     * @return void
     */
    public function notificationTest()
    {
        try {
            $user = User::find(1);

            $data = [
                'title' => 'Test Mail Notification',
                'body' => 'Test Mail Notification body',
                'from_user_id' => FacadesAuth::user()->id,
                'to_user_id' => $user->id,
            ];
            $user->notify(new EmailNotification($data));

            return response()->json(['status_code' => 200, 'message' => 'Notification has been sent successfully.', 'data' => []], 200);
        } catch (Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }
    
    /**
     * count
     *
     * @return void
     */
    public function count() {
        try {
            $userId = Auth::id();
            
            $unReadCount = Notification::where('to_user_id', $userId)->where('is_read', "0")->count();
            $readCount = Notification::where('to_user_id', $userId)->where('is_read', "1")->count();

            $data = [
                'unread_count' => $unReadCount,
                'read_count' => $readCount,
            ];
            return response()->json([
                'status_code' => 200,
                'data' => $data
            ], 200);
        } catch (Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }
}