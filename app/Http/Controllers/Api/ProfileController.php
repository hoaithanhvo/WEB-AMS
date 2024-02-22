<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\CheckoutRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Laravel\Passport\TokenRepository;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Support\Facades\Gate;
use DB;

class ProfileController extends Controller
{

    /**
     * The token repository implementation.
     *
     * @var \Laravel\Passport\TokenRepository
     */
    protected $tokenRepository;

    /**
     * Create a controller instance.
     *
     * @param  \Laravel\Passport\TokenRepository  $tokenRepository
     * @param  \Illuminate\Contracts\Validation\Factory  $validation
     * @return void
     */
    public function __construct(TokenRepository $tokenRepository, ValidationFactory $validation)
    {
        $this->validation = $validation;
        $this->tokenRepository = $tokenRepository;
    }

    /**
     * Display a listing of requested assets.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v4.3.0]
     *
     * @return array
     */
    public function requestedAssets()
    {
        $checkoutRequests = CheckoutRequest::where('user_id', '=', Auth::user()->id)->get();

        $results = [];
        $results['total'] = $checkoutRequests->count();

        foreach ($checkoutRequests as $checkoutRequest) {

            // Make sure the asset and request still exist
            if ($checkoutRequest && $checkoutRequest->itemRequested()) {
                $results['rows'][] = [
                    'image' => e($checkoutRequest->itemRequested()->present()->getImageUrl()),
                    'name' => e($checkoutRequest->itemRequested()->present()->name()),
                    'type' => e($checkoutRequest->itemType()),
                    'qty' => (int) $checkoutRequest->quantity,
                    'location' => ($checkoutRequest->location()) ? e($checkoutRequest->location()->name) : null,
                    'expected_checkin' => Helper::getFormattedDateObject($checkoutRequest->itemRequested()->expected_checkin, 'datetime'),
                    'request_date' => Helper::getFormattedDateObject($checkoutRequest->created_at, 'datetime'),
                ];
            }
        }

        return $results;
    }


    /**
     * Delete an API token
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v6.0.5]
     *
     * @return \Illuminate\Http\Response
     */
    public function createApiToken(Request $request) {

        if (!Gate::allows('self.api')) {
            abort(403);
        }

        $accessTokenName = $request->input('name', 'Auth Token');

        if ($accessToken = Auth::user()->createToken($accessTokenName)->accessToken) {

            // Get the ID so we can return that with the payload
            $token = DB::table('oauth_access_tokens')->where('user_id', '=', Auth::user()->id)->where('name','=',$accessTokenName)->orderBy('created_at', 'desc')->first();
            $accessTokenData['id'] = $token->id;
            $accessTokenData['token'] = $accessToken;
            $accessTokenData['name'] = $accessTokenName;
            return response()->json(Helper::formatStandardApiResponse('success', $accessTokenData, 'Personal access token '.$accessTokenName.' created successfully'));
        }
        return response()->json(Helper::formatStandardApiResponse('error', null, 'Token could not be created.'));

    }

    /**
     * Create an API token from username and password
     *
     * @return \Illuminate\Http\Response
     */
    public function createApiTokenFromUserNamePassword(Request $request)
    {
        $credentials = $request->only('username', 'password');
        $accessTokenName = $request->input('username') . "_mobile_access_token_";

        if (Auth::attempt($credentials)) {
            $user = Auth::user();
            $lastAccessTokenName = DB::table('oauth_access_tokens')->select('name')->where('user_id', '=', Auth::user()->id)->where('name','like',$accessTokenName . '%')->orderBy('created_at', 'desc')->first();
            if ($lastAccessTokenName == null) {
                $order = 0;
            } else {
                $parts = explode('_', $lastAccessTokenName->name);
                $order = end($parts) + 1;
            }

            $accessTokenName = $accessTokenName . $order;
            $accessToken = $user->createToken($accessTokenName)->accessToken;

            // Get the ID so we can return that with the payload
            // $token = DB::table('oauth_access_tokens')->where('user_id', '=', Auth::user()->id)->where('name','=',$accessTokenName)->orderBy('created_at', 'desc')->first();
            $token = DB::table('oauth_access_tokens')
                        ->join('users', 'oauth_access_tokens.user_id', '=', 'users.id')
                        ->select('oauth_access_tokens.*', 'users.first_name', 'users.last_name')
                        ->where('oauth_access_tokens.user_id', '=', Auth::user()->id)
                        ->where('oauth_access_tokens.name', '=', $accessTokenName)
                        ->orderBy('oauth_access_tokens.created_at', 'desc')
                        ->first();

            $accessTokenData['id'] = $token->id;
            $accessTokenData['user_full_name'] = $token->first_name . $token->last_name;
            $accessTokenData['token'] = $accessToken;
            $accessTokenData['expires_at'] = $token->expires_at;
            return response()->json(Helper::formatStandardApiResponse('success', $accessTokenData, 'Login successfully.'));
        }
        return response()->json(Helper::formatStandardApiResponse('error', null, 'Invalid username or password. Please try again.'));
    }

     /**
     * Delete an API token from mobile
     *
     * @return \Illuminate\Http\Response
     */
    public function deleteApiTokenMobile($tokenId) {

        if (!Gate::allows('self.api')) {
            abort(403);
        }

        $token = $this->tokenRepository->findForUser(
            $tokenId, Auth::user()->getAuthIdentifier()
        );

        if (is_null($token)) {
            return new Response('', 404);
        }

        $token->revoke();

        return response()->json(Helper::formatStandardApiResponse('success'));
    }

    /**
     * Delete an API token
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v6.0.5]
     *
     * @return \Illuminate\Http\Response
     */
    public function deleteApiToken($tokenId) {

        if (!Gate::allows('self.api')) {
            abort(403);
        }

        $token = $this->tokenRepository->findForUser(
            $tokenId, Auth::user()->getAuthIdentifier()
        );

        if (is_null($token)) {
            return new Response('', 404);
        }

        $token->revoke();

        return new Response('', Response::HTTP_NO_CONTENT);

    }


    /**
     * Show user's API tokens
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @since [v6.0.5]
     *
     * @return \Illuminate\Http\Response
     */
    public function showApiTokens(Request $request) {

        if (!Gate::allows('self.api')) {
            abort(403);
        }
        
        $tokens = $this->tokenRepository->forUser(Auth::user()->getAuthIdentifier());
        $token_values = $tokens->load('client')->filter(function ($token) {
            return $token->client->personal_access_client && ! $token->revoked;
        })->values();

        return response()->json(Helper::formatStandardApiResponse('success', $token_values, null));

    }



}
