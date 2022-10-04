<?php

namespace Yarm\Comments;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Spatie\Honeypot\ProtectAgainstSpam;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Yarm\Comments\Mail\MailSent;
use App\Models\Ref;
use App\User;

class CommentController extends Controller implements CommentControllerInterface
{
    public function __construct()
    {
        $this->middleware('web');

        if (Config::get('comments.guest_commenting') == true) {
            $this->middleware('auth')->except('store');
            $this->middleware(ProtectAgainstSpam::class)->only('store');
        } else {
            $this->middleware('auth');
        }
    }

    function sendEmail($request)
    //Changes for YARM
    {
        $parent_commenter_email = null;
        // New comment
        if (isset($request['commentable_id'])) {
            $ref_id = $request['commentable_id'];
            $user_id = Ref::where('id', '=', $request['commentable_id'])->first()->user_id;
        }
        // Reply to a comment
        else {
            $ref_id = Comment::where('id', '=',  basename(URL::current()))->first()->commentable_id;
            $user_id = Ref::where('id', '=', $ref_id)->first()->user_id;

            $parent_id = Comment::where('id', '=',  basename(URL::current()))->first()->id;
            $parent_commenter_id = Comment::where('id', '=', $parent_id)->first()->commenter_id;
            $parent_commenter_email = User::where('id', '=', $parent_commenter_id)->first()->email;
        }

        $email = User::where('id', '=', $user_id)->first()->email;
        $name = User::where('id', '=', $user_id)->first()->name;

        $data['ref_id'] = $ref_id;
        $data['name'] = $name;
        $data['commenter_name'] = Auth::user()->name;
        $data['message'] = $request['message'];
        $data['url'] = URL::previous();

        if ($parent_commenter_email) {
            if ($parent_commenter_email != $email) {
                $data['subject'] = $data['commenter_name'] . ' ' . __('commented on your comment in Ref with id' . ' ' . $data['ref_id']);
                Mail::to($parent_commenter_email)->send(new MailSent($data));
            }
        }

        if (Auth::user()->id != $user_id) {
            $data['subject'] = $data['commenter_name'] . ' ' . __('commented on your Ref with id' . ' ' . $data['ref_id']);
            Mail::to($email)->send(new MailSent($data));
        }
    }

    /**
     * Creates a new comment for given model.
     */
    public function store(Request $request)
    {
        // If guest commenting is turned off, authorize this action.
        if (Config::get('comments.guest_commenting') == false) {
            Gate::authorize('create-comment', Comment::class);
        }

        // Define guest rules if user is not logged in.
        if (!Auth::check()) {
            $guest_rules = [
                'guest_name' => 'required|string|max:255',
                'guest_email' => 'required|string|email|max:255',
            ];
        }

        // Merge guest rules, if any, with normal validation rules.
        Validator::make($request->all(), array_merge($guest_rules ?? [], [
            'commentable_type' => 'required|string',
            'commentable_id' => 'required|string|min:1',
            'message' => 'required|string'
        ]))->validate();

        $model = $request->commentable_type::findOrFail($request->commentable_id);

        $commentClass = Config::get('comments.model');
        $comment = new $commentClass;

        if (!Auth::check()) {
            $comment->guest_name = $request->guest_name;
            $comment->guest_email = $request->guest_email;
        } else {
            $comment->commenter()->associate(Auth::user());
        }

        $comment->commentable()->associate($model);
        $comment->comment = $request->message;
        $comment->approved = !Config::get('comments.approval_required');
        $comment->save();

        self::sendEmail($request);

        return Redirect::to(URL::previous() . '#comment-' . $comment->getKey());
    }

    /**
     * Updates the message of the comment.
     */
    public function update(Request $request, Comment $comment)
    {
        Gate::authorize('edit-comment', $comment);

        Validator::make($request->all(), [
            'message' => 'required|string'
        ])->validate();

        $comment->update([
            'comment' => $request->message
        ]);

        self::sendEmail($request);

        return Redirect::to(URL::previous() . '#comment-' . $comment->getKey());
    }

    /**
     * Deletes a comment.
     */
    public function destroy(Comment $comment)
    {
        Gate::authorize('delete-comment', $comment);

        if (Config::get('comments.soft_deletes') == true) {
			$comment->delete();
		}
		else {
			$comment->forceDelete();
		}

        return Redirect::back();
    }

    /**
     * Creates a reply "comment" to a comment.
     */
    public function reply(Request $request, Comment $comment)
    {
        Gate::authorize('reply-to-comment', $comment);

        Validator::make($request->all(), [
            'message' => 'required|string'
        ])->validate();

        $commentClass = Config::get('comments.model');
        $reply = new $commentClass;
        $reply->commenter()->associate(Auth::user());
        $reply->commentable()->associate($comment->commentable);
        $reply->parent()->associate($comment);
        $reply->comment = $request->message;
        $reply->approved = !Config::get('comments.approval_required');
        $reply->save();

        self::sendEmail($request);

        return Redirect::to(URL::previous() . '#comment-' . $reply->getKey());
    }
}
