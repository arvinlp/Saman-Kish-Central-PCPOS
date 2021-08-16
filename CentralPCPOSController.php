<?php
/*
* @Author: arvinlp 
 * @Date: 2019-12-28 18:57:49 
 * Copyright by Arvin Loripour 
 * WebSite : http://www.arvinlp.ir 
 * @Last Modified by: Arvin.Loripour
 * @Last Modified time: 2021-05-30 10:23:54
 */

namespace App\Http\Controllers\V1\Client\Payment;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller as BaseController;

// GuzzleHttp
use GuzzleHttp;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request as GRequest;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\ConnectException;

use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;
// Date And Time
use Carbon\Carbon;

class CentralPCPOSController extends BaseController{
	
    private $access_token = null;
    private $refresh_token = null;
    private $token_type = null;

	public function __construct(){
    // refresh Access Token
        if(!$terminalInfo = TerminalToken::latest()->first()){
            self::refreshToken();
        }else{
            if($terminalInfo->expired_at <= Carbon::now()){
                self::refreshToken();
            }else{
                $this->access_token     = $terminalInfo->access_token;
                $this->refresh_token    = $terminalInfo->refresh_token;
                $this->token_type       = $terminalInfo->token_type;
            }
        }
	}

    public function startPayment(Request $request){
        $this->validate($request, [
            'amount'        => 'required',
            'res_num'   => 'required',
        ],[
            'amount.required' => 'مبلغ وارد نشده است.',
            'res_num.required' => 'شماره رسید/فاکتور وارد نشده است.',
        ]);

        if(!$TerminalID = self::getMineTerminal()){
			return response()->json([
				'message'   =>['خطا در پایانه یافت نشد !',"کد خطا 409"],
			], 409);
        }

        if(!$Identifier = self::getIdentifierPaymentPCPOS()){
			return response()->json([
				'message'   =>['خطا در دریافت شماره تراکنش یافت نشد !',"کد خطا 409"],
			], 409);
        }
        
        $Amount = $request->input('amount');
        $ResNum = $request->input('res_num');
        $AccountType = 0;
        $TransactionType = 0;

        $notifiable = '{';
        $notifiable .= '"FooterMessage":"متن پایین رسید پرداخت",';
        $notifiable .= '"PrintItems":[';
        $notifiable .= '{';
        $notifiable .= '"Item":"آیتم اول",';
        $notifiable .= '"Value":"محتوا اول",';
        $notifiable .= '"Alignment":0,';
        $notifiable .= '"ReceiptType":0';
        $notifiable .= '}';
        $notifiable .= ']';
        $notifiable .= '}';

        $userNotifiable = json_decode($notifiable);

        $requestBody = [
            'TerminalID'        => (string)$TerminalID,
            'Amount'            => (string)$Amount,
            'AccountType'       => $AccountType, // 
            'ResNum'            => (string)$ResNum,
            'Identifier'        => $Identifier,
            'userNotifiable'    => $userNotifiable,
            'TransactionType'   => $TransactionType,
        ];

        try{
            
            $client = new GuzzleHttp\Client();

            $promise = $client->postAsync("https://cpcpos.seppay.ir/v1/PcPosTransaction/StartPayment",[
                'headers' => [
                    'Authorization' => "{$this->token_type} {$this->access_token}"
               ],
               'json' => $requestBody,
            ])->then(
                function (ResponseInterface $res){
                    $access = json_decode($res->getBody());
                    if($access->IsSuccess == true){
                        /**
                        * در صورت نیاز به ذخیره اطلاعات برگشتی پرداخت موفق از این بخش
                        * می توانید استفاده کنید.
                        */
                    }

                    return $access;
                },
                function (RequestException $e) {
                    $response = [];
                    $response->data = $e->getMessage();
            
                    return $response;
                }
            );
            $response = $promise->wait();
            return response()->json($response);
            
        }catch(ClientException $e){
            return abort(404);
        }catch(ServerException $e){
            return abort(500);
        }
    }

    /**
     * Refresh Token For Next Requests
     */
    private function refreshToken(){
        if(!$sep_username = Setting::where('key','sep_username')->select('value')->first()) $sep_username = "*********";// اطلاعات این بخش را از شرکت سامان کیش درخواست دهید.
        else  $sep_username = $sep_username->value;
        if(!$sep_password = Setting::where('key','sep_password')->select('value')->first()) $sep_password = "*********";// اطلاعات این بخش را از شرکت سامان کیش درخواست دهید.
        else  $sep_password = $sep_password->value;
        //
        if(!$sep_scope = Setting::where('key','sep_scope')->select('value')->first()) $sep_scope = "*********";// اطلاعات این بخش را از شرکت سامان کیش درخواست دهید.
        else  $sep_scope = $sep_scope->value;
        //
        if(!$sep_grant_type = Setting::where('key','sep_grant_type')->select('value')->first()) $sep_grant_type = "*********";// اطلاعات این بخش را از شرکت سامان کیش درخواست دهید.
        else  $sep_grant_type = $sep_grant_type->value;

        try{
            $client = new GuzzleHttp\Client();

            $response = $client->request('POST', "https://idn.seppay.ir/connect/token",[
                'headers' => [
                    'Authorization' => 'Basic *********'// اطلاعات این بخش را از شرکت سامان کیش درخواست دهید.
               ],
                'form_params' => [
                    'grant_type' => $sep_grant_type,
                    'username' => $sep_username,
                    'password' => $sep_password,
                    'scope' => $sep_scope,
                ]
            ]);
            $access = json_decode($response->getBody());
            $newToken = new TerminalToken;
            $newToken->access_token = $access->access_token;
            $newToken->token_type = $access->token_type;
            $newToken->expires_in = $access->expires_in;
            $newToken->expired_at = Carbon::now()->addSeconds($access->expires_in);
            if(isset($access->refresh_token))$newToken->refresh_token = $access->refresh_token;
            $newToken->save();

            $this->access_token = $access->access_token;
            $this->token_type   = $access->token_type;
            if(isset($access->refresh_token))$this->refresh_token   = $access->refresh_token;
            
        }catch(ClientException $e){
            return abort(404);
        }catch(ServerException $e){
            return abort(500);
        }
    }

    private function getIdentifierPaymentPCPOS(){
        try{
            $client = new GuzzleHttp\Client();

            $response = $client->request('POST', "https://cpcpos.seppay.ir/v1/PcPosTransaction/ReciveIdentifier",[
                'headers' => [
                    'Authorization' => "{$this->token_type} {$this->access_token}"
               ]
            ]);
            $access = json_decode($response->getBody());
            
            return $access->Data->Identifier;
        }catch(ClientException $e){
            return null;
        }catch(ServerException $e){
            return null;
        }
    }

    private function getMineTerminal(){
        if($terminal_id = TerminalUser::with('terminal')->where('user_id',$this->user_id)->first()){
            return $terminal_id->terminal->code;
        }
        return null;
    }
}
