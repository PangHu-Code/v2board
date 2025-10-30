<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

class Client
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $token = $request->input('token');
        if (empty($token)) {
            return redirect('https://img.cmvideo.cn/publish/noms/2023/12/06/1O4SHFIFR36BD.gif');
        }
        $submethod = (int)config('v2board.show_subscribe_method', 0);
        switch ($submethod) {
            case 0:
                break;
            case 1:
                if (!Cache::has("otpn_{$token}")) {
                    return redirect('https://img.cmvideo.cn/publish/noms/2023/12/06/1O4SHFIFR36BD.gif');
                }
                $usertoken = Cache::pull("otpn_{$token}");
                Cache::forget("otp_{$usertoken}");
                $token = $usertoken;
                break;
            case 2:
                $usertoken = Cache::get("totp_{$token}");
                if (!$usertoken) {
                    $timestep = (int)config('v2board.show_subscribe_expire', 5) * 60;
                    $counter = floor(time() / $timestep);
                    $counterBytes = pack('N*', 0) . pack('N*', $counter);
                    $idhash = Helper::base64DecodeUrlSafe($token);
                    if (strpos($idhash, ':') === false) {
                        return redirect('https://img.cmvideo.cn/publish/noms/2023/12/06/1O4SHFIFR36BD.gif');
                    }
                    $parts = explode(':', $idhash, 2);
                    [$userid, $clienthash] = $parts;
                    if (!$userid || !$clienthash) {
                        return redirect('https://img.cmvideo.cn/publish/noms/2023/12/06/1O4SHFIFR36BD.gif');
                    }
                    $user = User::where('id', $userid)->select('token')->first();
                    if (!$user) {
                        return redirect('https://img.cmvideo.cn/publish/noms/2023/12/06/1O4SHFIFR36BD.gif');
                    }
                    $usertoken = $user->token;
                    $hash = hash_hmac('sha1', $counterBytes, $usertoken, false);
                    if ($clienthash !== $hash) {
                        return redirect('https://img.cmvideo.cn/publish/noms/2023/12/06/1O4SHFIFR36BD.gif');
                    }
                    Cache::put("totp_{$token}", $usertoken, $timestep);
                }
                $token = $usertoken;
                break;
            default:
                break;
        }
        $user = User::where('token', $token)->first();
        if (!$user) {
            return redirect('https://img.cmvideo.cn/publish/noms/2023/12/06/1O4SHFIFR36BD.gif');
        }
        $request->merge([
            'user' => $user
        ]);
        return $next($request);
    }
}
