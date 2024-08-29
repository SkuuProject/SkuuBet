<?php namespace App\Currency\Commerce\Utils;

use App\Currency\Commerce\CommerceCurrency;
use App\Currency\Commerce\Utils\Coinpayments\CoinpaymentsAPI;
use App\Currency\Currency;
use App\Currency\Token\TokenUSDC;
use App\Currency\Token\TokenUSDT;
use App\Games\Kernel\ThirdParty\Phoenix\PhoenixGame;
use App\License\License;
use App\Models\CommerceCharge;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class CoinpaymentsCommerce {

  public static function generateWalletAddress(Currency $currency, ?User $user): string {
    $charge = CommerceCharge::where('user', $user->_id)->where('currency', $currency->id())->where('gotPayment', '!=', true)->first();
    if($charge != null) return $charge->address;

    $public_key = '64c6c37dbc27063b350dfcfb4897443cb665b5267e125cbfd8a6fbbf0edfad2d';
    $private_key = '55332c024C54417A106F903c91f36931Cf82ca946083EC7595E10bb874694ccC';

    $cps_api = new CoinpaymentsAPI($private_key, $public_key, 'json');

    $curr = $currency->coinpaymentsId();
    // if ($curr == 'LTC') $curr = 'LTCT'; // should be commented after testing

    try {
      $callback_address_response = $cps_api->GetOnlyCallbackAddress($curr);
    } catch (Exception $e) {
      Log::info('Error: ' . $e);
      throw new \Exception('Invalid status code for Coinpayments');
    }

    Log::info('get_callback_address-response-----');
    Log::info($callback_address_response);

    $foundOne = false;

    if($callback_address_response['error'] == 'ok' && isset($callback_address_response['result']['address'])) {
      // Log::info('found coinbase name!');
      CommerceCharge::create([
        'user' => $user->_id,
        'currency' => $currency->id(),
        'address' => $callback_address_response['result']['address'],
        // 'code' => $callback_address_response['result']['dest_tag']
      ]);

      $foundOne = true;
    }

    return $foundOne ? self::generateWalletAddress($currency, $user) : 'Error';
  }

  public static function generateWalletAddressForNonCommerceCurrency(Currency $currency, string $coinbaseName, ?User $user): string {
    $charge = CommerceCharge::where('user', $user->_id)->where('currency', $currency->id())->where('gotPayment', '!=', true)->first();
    if($charge != null) return $charge->address;

    // $json = json_decode((new PhoenixGame())->curl('https://phoenix-gambling.com/license/commerce/charge/create', [
    //   'license' => (new License())->getKey(),
    //   'commerceApiKey' => Settings::get('[Coinbase Commerce] API Key', '')
    // ]), true);

    // if(isset($json['addresses'][$coinbaseName])) {
    //   CommerceCharge::create([
    //     'user' => $user->_id,
    //     'currency' => $currency->id(),
    //     'address' => $json['addresses'][$coinbaseName],
    //     'code' => $json['code']
    //   ]);

    //   return $json['addresses'][$coinbaseName];
    // }

    $json = json_decode(self::curlAddress(), true);

    foreach (Currency::getAllSupportedCoins() as $c) {
      if(!($c instanceof CommerceCurrency)) continue;

      if(isset($json['data']['addresses'][$coinbaseName])) {
        Log::info('found coinbase name!');
        CommerceCharge::create([
          'user' => $user->_id,
          'currency' => $c->id(),
          'address' => $json['data']['addresses'][$coinbaseName],
          'code' => $json['data']['code']
        ]);

        return $json['data']['addresses'][$coinbaseName];

      }
    }

    return 'Error';
  }

  public static function handle(Request $request): void {
    $cp_merchant_id = '9b9d7c436422d1a6be2354e95f43bd94';
    $cp_ipn_secret = 'apowgmiqrgq4ngq';
    $cp_debug_email = 'makadamguess231@gmail.com';

    function errorAndDie($error_msg) {
      Log::info('coinpayments-error: '.$error_msg);
    }

    $requestBody = $request->all();
    if ($requestBody === FALSE || empty($requestBody)) {
        errorAndDie('Error reading POST data');
    }

    $ipn_mode = $request->input('ipn_mode');
    if (!isset($ipn_mode) || $ipn_mode != 'hmac') {
        errorAndDie('IPN Mode is not HMAC');
    }

    
    $headerValue = $_SERVER['HTTP_HMAC']; // $request->header('hmac');
    
    $headers = $request->headers->all();
    // Log::info('coinpayments-ipn-headers---'.$headerValue);
    // Log::info($headers);

    if (!isset($headerValue) || empty($headerValue)) {
        errorAndDie('No HMAC signature sent.');
    }

    if (!isset($requestBody['merchant']) || $requestBody['merchant'] != trim($cp_merchant_id)) {
        errorAndDie('No or incorrect Merchant ID passed');
    }

    $requestData = file_get_contents('php://input');
    // Log::info($requestBody);
    // Log::info($requestData);
    $hmac = hash_hmac("sha512", $requestData, trim($cp_ipn_secret));
    if (!hash_equals($hmac, $headerValue)) {
    //if ($hmac != $_SERVER['HTTP_HMAC']) { <-- Use this if you are running a version of PHP below 5.6.0 without the hash_equals function
        errorAndDie('HMAC signature does not match');
    }

    // HMAC Signature verified at this point, load some variables.

    // Log::info($requestBody);

    $status = intval($requestBody['status']);
    if ($requestBody['ipn_type'] == 'deposit' && ($status >= 100 || $status == 2)) {
      // payment is complete or queued for nightly payout, success
      Log::info('coinpayments-ipn-status-success: '.$status);

      $currency = self::findByCoinpaymentsId($requestBody['currency']);
      if(!$currency) {
        Log::info('coinpayments-ipn-currency-null: '.$currency);
        return;
      }

      $charge = CommerceCharge::where('address', $requestBody['address'])->where('currency', $currency->id())->first();
      if(!$charge) {
        Log::info('coinpayments-ipn-charge-null: '.$charge);
        return;
      }

      $user = User::where('_id', $charge->user)->first();
      if(!$user) {
        Log::info('coinpayments-ipn-user-null: '.$user);
        return;
      }  

      if($currency->acceptThirdParty(intval($requestBody['confirms']), $user, $requestBody['deposit_id'], floatval($requestBody['amount']), 0)) {
          CommerceCharge::where('address', $requestBody['address'])->update([
            'gotPayment' => true
          ]);
      }
    } else if ($status < 0) {
      //payment error, this is usually final but payments will sometimes be reopened if there was no exchange rate conversion or with seller consent
      Log::info('coinpayments-ipn-status-error: '.$status);
    } else {
      //payment is pending, you can optionally add a note to the order page
      Log::info('coinpayments-ipn-status-pending: '.$status);
    }

  }

  private static function findByCoinpaymentsId(string $coinpaymentsId): ?Currency {
    switch($coinpaymentsId) {
      case "PUSDC":
      case "USDC":
        return new TokenUSDC();
      case "USDT":
        return new TokenUSDT();
    }

    if ($coinpaymentsId === "LTCT") {
      $coinpaymentsId = "LTC";
    }

    Log::info('coinpaymentId:' . $coinpaymentsId);

    foreach (Currency::getAllSupportedCoins() as $currency) {
      if(!($currency instanceof Currency)) continue;
      if($currency->coinpaymentsId() === $coinpaymentsId) return $currency;
    }

    return null;
  }

}
