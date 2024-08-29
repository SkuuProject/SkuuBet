<?php

use App\Games\Kernel\ThirdParty\ThirdPartyGame;
use App\Utils\Demo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use App\Games\Kernel\ThirdParty\FiversCan\FiversCanGame;
use App\Games\Kernel\ThirdParty\WorldSlotGame\WorldSlotGameGame;
use App\Currency\Currency;
use App\Utils\APIResponse;
use App\Models\User;

Route::get('/avatar/{hash}', function ($hash) {
  $size = 100;
  $icon = new \Jdenticon\Identicon();
  $icon->setValue($hash);
  $icon->setSize($size);

  $style = new \Jdenticon\IdenticonStyle();
  $style->setBackgroundColor('#21232a');
  $icon->setStyle($style);

  $icon->displayImage('png');
  return response('')->header('Content-Type', 'image/png');
});

Route::post('/license', function() {
  $license = new \App\License\License();

  //if(!$license->isValid()) return [ 'isValid' => false ];

  $pluginIds = [];

  foreach ((new \App\License\Plugin\PluginManager())->fetch() as $item)
    if($item->isEnabled())
      $pluginIds[] = $item->id();

  return [
    'isValid' => true,
    'enabledFeatures' => $license->features(),
    'plugins' => $pluginIds
  ];
});

Route::any('/installer/firstTimeUpdate', function() {
  if(\App\Models\Settings::get('[Installer] First time update', 'false') !== 'false') return \App\Utils\APIResponse::reject(1, 'Invalid state');
  \App\Models\Settings::set('[Installer] First time update', 'true');
  return (new \App\Updater\Updater())->update();
});

Route::prefix('internal')->middleware('internal')->group(function() {
  Route::get('license', function() {
    return \App\Utils\APIResponse::success([ 'key' => (new \App\License\License())->getKey() ]);
  });
});

Route::get('/{url?}', function ($url = null) {
  if($url && str_starts_with($url, "admin")) {
    if(!Demo::isDemo(true) && (auth('sanctum')->guest() || !auth('sanctum')->user()->checkPermission(new \App\Permission\DashboardPermission()))) return view('errors.403');
    return view('layouts.admin');
  }

  return view('layouts.app');
})->where('url', '[ \/\w\:.-]*');

// Route::post('/gold_api/user_balance', function (Request $request) {
//     $data = $request->all();

//     $user = \App\Models\User::where('name', $data['user_code'])->first();

//     $currencySetting = \App\Models\Settings::get('currencies', 'local_suitpay');
//     $currencyData = json_decode($currencySetting, true)[0];
//     //$currency = \App\Currency\Currency::find($currencyData);
//     $currency = \App\Currency\Currency::find('infura_eth');
//     $userBalance = $user->balance($currency);

//     return response()->json((new WorldSlotGameGame(''))->processUserBalance($user, $userBalance));
// });

Route::post('gold_api/user_balance', function (Request $request) {
    $data = $request->all();
    Log::info('user_balance called');
    //$currencySetting = \App\Models\Settings::get('currencies', 'local_suitpay');
    // $currencyData = json_decode($currencySetting, true)[0];
    // $currency = \App\Currency\Currency::find($currencyData);
    //$currency = \App\Currency\Currency::find('infura_eth'); // TODO use selected currency
    $user = \App\Models\User::where('email', $data['user_code'])->orWhere('name', $data['user_code'])->orWhere('phone', preg_replace("/[^0-9]/", "", $data['user_code']))->first();

    if (!$user) {
        $body['msg'] = 'INVALID_USER';
    }

    $currency = \App\Currency\Currency::find($user->selected_currency);
    Log::info($currency->fiatNumberFormat($currency->convertTokenToUSD($user->balance($currency)->get())));
    $body = [
        'status' => !$user ? false : true,
        //'user_balance' => !$user ? 0 : $user->balance($currency)->get(),
        'user_balance' => !$user ? 0 : $currency->fiatNumberFormat($currency->convertTokenToUSD($user->balance($currency)->get())),
    ];

    if (!$user) {
        $body['msg'] = 'INVALID_USER';
    }

    return APIResponse::success($body);
});

// Route::post('/gold_api/game_callback', function (Request $request) {
//     $data = $request->all();

//     $user = \App\Models\User::where('name', $data['user_code'])->first();

//     $currencySetting = \App\Models\Settings::get('currencies', 'local_suitpay');
//     $currencyData = json_decode($currencySetting, true)[0];
//     //$currency = \App\Currency\Currency::find($currencyData);
//     $currency = \App\Currency\Currency::find('infura_eth');
//     $userBalance = $user->balance($currency);

//     //return response()->json((new FiversCanGame(''))->processUserBalance($user, $userBalance));
//     $gameType = $data['game_type'];
//     $gameData = $data[$gameType];
//     $gameData['type'] = $gameType;
//     \Illuminate\Support\Facades\Log::info('game data', $gameData);
//     return response()->json((new WorldSlotGameGame($gameData))->processCallback($currency));
// });

Route::post('gold_api/game_callback', function (Request $request) {
    $data = $request->all();
    Log::info('game_callback called');
    Log::info($data);
    //$currencySetting = \App\Models\Settings::get('currencies', 'local_suitpay');
    // $currencyData = json_decode($currencySetting, true)[0];
    // $currency = \App\Currency\Currency::find($currencyData);
    //$currency = \App\Currency\Currency::find('infura_eth');
    //$currency = \App\Currency\Currency::find(User::where($data['user_code'])->selected_currency);
    $gameData = $data[$data['game_type']];
     // $gameList = Cache::has('worldslotgame:providerGameList') ? Cache::get('worldslotgame:providerGameList') : [];
     $gameList = Cache::has('FiversCan:providerGameList') ? Cache::get('FiversCan:providerGameList') : [];

    $game = collect($gameList)->first(function ($game) use ($gameData) {
        return $game['game_code'] === $gameData['game_code'];
    });

    $user = \App\Models\User::where('email', $data['user_code'])->orWhere('name', $data['user_code'])->orWhere('phone', preg_replace("/[^0-9]/", "", $data['user_code']))->first();

    if (!$user) {
        $body['msg'] = 'INVALID_USER';
    }

    $currency = \App\Currency\Currency::find($user->selected_currency);

    $body = [
        "round_id" => $gameData['round_id'],
        "game_name" => $game['game_name'],
        "game_code" => $gameData['game_code'],
        "banner" => $game['banner'],
        "game_type" => $data['game_type'],
        "type" => $gameData['type'],
        "provider" => [
            "name" => $game['provider']['name'],
            "code" => $gameData['provider_code'],
        ],
        "transaction" => [
            "agent_balance" => $data['agent_balance'],
            "user_code" => $data['user_code'],
            "user_balance" => $data['user_balance'],
            "bet" => $gameData['bet'],
            "win" => $gameData['win'],
            "txn_id" => $gameData['txn_id'],
            "txn_type" => $gameData['txn_type']
        ],
    ];

//    $result = (new WorldSlotGameGame($body))->processCallback($currency);
        $result = (new FiversCanGame($body))->processCallback($currency);


    return APIResponse::success($result);
});

// Route::post('/gold_api/money_callback', function (Request $request) {
//     $data = $request->all();

//     $user = \App\Models\User::where('name', $data['user_code'])->first();

//     $currencySetting = \App\Models\Settings::get('currencies', 'local_suitpay');
//     $currencyData = json_decode($currencySetting, true)[0];
//     //$currency = \App\Currency\Currency::find($currencyData);
//     $currency = \App\Currency\Currency::find('infura_eth');
//     $userBalance = $user->balance($currency);

//     //return response()->json((new FiversCanGame(''))->processUserBalance($user, $userBalance));
//     $gameType = $data['game_type'];
//     $gameData = $data[$gameType];
//     $gameData['type'] = $gameType;
//     \Illuminate\Support\Facades\Log::info('game data', $gameData);
//     return response()->json((new WorldSlotGameGame($gameData))->processCallback($currency));
// });

// Route::post('/gold_api', function (Request $request) {
//     $data = $request->all();

//     $user = \App\Models\User::where('name', $data['user_code'])->first();

//     $currencySetting = \App\Models\Settings::get('currencies', 'local_suitpay');
//     $currencyData = json_decode($currencySetting, true)[0];
//     //$currency = \App\Currency\Currency::find($currencyData);
//     $currency = \App\Currency\Currency::find('infura_eth');
//     $userBalance = $user->balance($currency);

//     if ('user_balance' === $data['method']) {
//          return response()->json((new FiversCanGame(''))->processUserBalance($user, $userBalance));
//      // return response()->json((new WorldSlotGameGame(''))->processCallback($userBalance));
//     } else if ('transaction' === $data['method']) {
//         $gameType = $data['game_type'];
//         $gameData = $data[$gameType];
//         $gameData['type'] = $gameType;
//         \Illuminate\Support\Facades\Log::info('game data', $gameData);
//         return response()->json((new FiversCanGame($gameData['provider_code'], $gameData))->processTransaction($user, $currency, $gameData));
//     }
// });

Route::post('/txNotify/{txid}', function ($txid) {
  Log::info('xmr wallet has received transaction!');
  Log::info($txid);
  return APIResponse::success(['result' => Currency::find('native_xmr')->process($txid)]);
});
