<?php

namespace App\Http\Controllers;

use App\Model\User;
use DB;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Mail;
use Validator;
use JWTAuth;

class AuthController extends Controller
{

    public function register(Request $request)
    {
        $credentials = $request->only('name', 'email', 'password');

        $rules = [
            'name' => 'required|max:255',
            'email' => 'required|email|max:255|unique:users',
        ];
        $validator = Validator::make($credentials, $rules);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'error' => $validator->messages()]);
        }

        $name = $request->name;
        $email = $request->email;
        $password = $request->password;

        $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make($password)]);

        $verification_code = str_random(30); //Generate verification code

        DB::table('user_verifications')->insert(['user_id' => $user->id, 'token' => $verification_code]);

        $subject = "Verificar el registro.";

        Mail::send('email.verify', compact('name', 'verification_code'),
            function ($mail) use ($email, $name, $subject) {
                $mail->from(getenv('FROM_EMAIL_ADDRESS'), "SENA");
                $mail->to($email, $name);
                $mail->subject($subject);
            });

        return response()->json(['ok' => true, 'message' => 'Thanks for signing up! Please check your email to complete your registration.']);
    }

    public function verifyUser($verification_code)
    {
        $check = DB::table('user_verifications')->where('token', $verification_code)->first();

        if (!is_null($check)) {
            $user = User::find($check->user_id);
            if ($user->is_verified == 1) {
                return response()->json([
                    'ok' => true,
                    'message' => 'Account already verified..',
                ]);
            }
            $user->update(['is_verified' => 1]);

            DB::table('user_verifications')->where('token', $verification_code)->delete();

            return view("verificar",  [
                'ok' => true,
                'message' => 'You have okfully verified your email address.',
            ]);
        }
        return view("verificar", ['ok' => false, 'message' => "Verification code is invalid."]);
    }

    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        $rules = [
            'email' => 'required|email',
            'password' => 'required',
        ];
        $validator = Validator::make($credentials, $rules);
        if ($validator->fails()) {
            return response()->json(['ok' => false, 'error' => $validator->messages()], 401);
        }

        $credentials['is_verified'] = 1;

        try {
            // attempt to verify the credentials and create a token for the user
            if (!$token = JWTAuth::attempt($credentials)) {
                
                return response()->json([
                    'ok' => false,
                    'error' => 'We cant find an account with this credentials. Please make sure you entered the right information and you have verified your email address.'],
                    404);
            }
        } catch (JWTException $e) {
            // something went wrong whilst attempting to encode the token
            return response()->json([
                'ok' => false,
                'error' => 'Failed to login, please try again.'],
                500);
        }

        // $token = auth()->setTTL(1000)->attempt($credentials);
        // all good so return the token
        return response()->json(['ok' => true, 'data' => ['token' => $token]], 200);
    }
    /**
     * Log out
     * Invalidate the token, so user cannot use it anymore
     * They have to relogin to get a new token
     *
     * @param Request $request
     */
    public function logout(Request $request)
    {
        $this->validate($request, ['token' => 'required']);

        try {
            JWTAuth::invalidate($request->input('token'));
            return response()->json(['ok' => true, 'message' => "You have okfully logged out."]);
        } catch (JWTException $e) {
            // something went wrong whilst attempting to encode the token
            return response()->json(['ok' => false, 'error' => 'Failed to logout, please try again.'], 500);
        }
    }

    public function recover(Request $request)
    {
        $user = User::where('email', $request->email)->first();
        
        if (!$user) {
            $error_message = "Your email address was not found.";
            return response()->json(['ok' => false, 'error' => ['email' => $error_message]], 401);
        }
        try {
            Password::sendResetLink($request->only('email'), function (Message $message) {
                $message->subject('Your Password Reset Link');
            });
        } catch (\Exception $e) {
            //Return with error
            $error_message = $e->getMessage();
            return response()->json(['ok' => false, 'error' => $error_message], 401);
        }
        return response()->json([
            'ok' => true, 'data' => ['message' => 'A reset email has been sent! Please check your email.'],
        ]);
    }

}
