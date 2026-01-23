<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use App\Mail\PasswordResetMail;


class AuthController extends Controller
{
    //
    public function login(Request $request)
    {
        // Validate incoming request
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        // Search user by email
        $user = User::where('email', $request->email)->first();

        // Invalid email OR invalid password
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password'
            ], 401);
        }

        // Generate Sanctum token
        $token = $user->createToken('auth_token')->plainTextToken;

        // Return successful response
        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'user'    => $user,
            'role'    => $user->role ?? 'player',
            'token'   => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Send password reset link
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Generate reset token
        $token = Str::random(64);
        $expiresAt = Carbon::now()->addHours(1); // Token expires in 1 hour

        // Delete any existing reset tokens for this email
        DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->delete();

        // Insert new reset token
        DB::table('password_reset_tokens')->insert([
            'email' => $request->email,
            'token' => Hash::make($token),
            'created_at' => Carbon::now(),
        ]);

        // In a real application, you would send an email here
        // For now, we'll return the token in the response (for testing)
        // In production, remove the token from the response and send it via email
        
        // Get frontend URL from environment or use default (Vue dev server)
        // Default to localhost:5173 for development
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');
        $resetUrl = rtrim($frontendUrl, '/') . '/reset-password?token=' . $token . '&email=' . urlencode($request->email);

        // Send email with reset link
        try {
            $userName = $user->username ?? $user->email;
            Mail::to($user->email)->send(new PasswordResetMail($resetUrl, $userName));
            
            return response()->json([
                'success' => true,
                'message' => 'Password reset link has been sent to your email address.',
            ]);
        } catch (\Exception $e) {
            // Log the error
            \Log::error('Failed to send password reset email', [
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);
            
            // In development, still return the URL for testing
            // In production, you might want to return a generic message
            if (env('APP_DEBUG', false)) {
                return response()->json([
                    'success' => true,
                    'message' => 'Password reset link has been generated. Email sending failed, but here is the link for testing:',
                    'reset_url' => $resetUrl,
                    'error' => 'Email sending failed: ' . $e->getMessage(),
                ]);
            } else {
                return response()->json([
                    'success' => true,
                    'message' => 'Password reset link has been sent to your email address. If you do not receive it, please check your spam folder or try again later.',
                ]);
            }
        }
    }

    /**
     * Reset password with token
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        // Find the reset token
        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$resetRecord) {
            \Log::info('Reset token not found', [
                'email' => $request->email,
                'token_length' => strlen($request->token ?? ''),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired reset token. Please request a new password reset link.'
            ], 400);
        }

        // Check if token is valid (not expired - 1 hour)
        $createdAt = Carbon::parse($resetRecord->created_at);
        $expiresAt = $createdAt->copy()->addHour();
        
        if (Carbon::now()->isAfter($expiresAt)) {
            // Delete expired token
            DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->delete();

            return response()->json([
                'success' => false,
                'message' => 'Reset token has expired. Please request a new one.'
            ], 400);
        }

        // Verify token
        if (!Hash::check($request->token, $resetRecord->token)) {
            // Log for debugging (remove in production)
            \Log::info('Token verification failed', [
                'email' => $request->email,
                'token_length' => strlen($request->token),
                'token_preview' => substr($request->token, 0, 10) . '...',
                'stored_token_preview' => substr($resetRecord->token, 0, 20) . '...',
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Invalid reset token. Please request a new password reset link.'
            ], 400);
        }

        // Find user and update password
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Update password
        $user->password = Hash::make($request->password);
        $user->save();

        // Delete the used reset token
        DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password has been reset successfully. You can now login with your new password.'
        ]);
    }
}
