public function checkUsername(Request $request): JsonResponse
    {
        // ១. ចាប់យកតម្លៃទោះបីជាផ្ញើមកក្នុងឈ្មោះ Key ណាក៏ដោយ
        $gameCode = $request->input('game_code') ?? $request->input('validation_code');
        $playerId = $request->input('player_id') ?? $request->input('user_id');
        $zoneId   = $request->input('zone_id') ?? $request->input('server_id') ?? '';

        if (!$gameCode || !$playerId) {
            return response()->json(['message' => 'game_code and player_id are required.'], 422);
        }

        try {
            $apiId     = trim(env('FLASH_TOPUP_API_ID', 'RSMNGJ90S66GU8IC'));
            $secretKey = trim(env('FLASH_TOPUP_SECRET_KEY'));
            $timestamp = time(); 
            $nonce     = Str::random(16); 

            $path = '/api/reseller/v2/check-id'; 
            $method = 'POST';

            // ២. 🎯 បង្កើតកញ្ចប់ Body ថ្មីស្អាត ដោយប្រើឈ្មោះ Key ផ្លូវការរបស់ FlashTopUp
            // និងបង្ខំឱ្យរៀបតាមលំដាប់តួអក្សរ A-Z ជានិច្ច (server_id -> user_id -> validation_code)
            $body = [
                'server_id'       => trim($zoneId),
                'user_id'         => trim($playerId),
                'validation_code' => strtolower(trim($gameCode)),
            ];

            // តម្រៀប Key ពី A-Z ដើម្បីឱ្យដូចទៅនឹង Postman មុននេះបេះបិទ
            ksort($body);

            // បម្លែងជា JSON String គ្រាប់ស្ងួត គ្មាន Space ចន្លោះ
            $rawJsonBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // ៣. 🎯 គណនា Signature តាមរូបមន្ត V2 (ខណ្ឌដោយសញ្ញា |) ដូច JavaScript លើ Postman 
            $payloadString = $method . '|' . $path . '|' . $timestamp . '|' . $nonce . '|' . $rawJsonBody;
            $signature = hash_hmac('sha256', $payloadString, $secretKey);

            // 🚀 បាញ់ទៅកាន់ FlashTopUp
            $response = Http::withHeaders([
                'Content-Type'    => 'application/json',
                'X-FT-API-ID'     => $apiId,
                'X-FT-Timestamp'  => $timestamp,
                'X-FT-Nonce'      => $nonce,
                'X-FT-Signature'  => $signature,
            ])
            ->withoutVerifying() 
            ->withBody($rawJsonBody, 'application/json')
            ->post('https://api.flashtopup.com' . $path);

            if ($response->successful()) {
                $apiData = $response->json();
                
                $playerName = $apiData['account_name'] 
                              ?? $apiData['data']['account_name'] 
                              ?? $apiData['player_name'] 
                              ?? null;

                return response()->json([
                    'message' => 'Done',
                    'result' => [
                        'player_name' => $playerName,
                        'username'    => $playerName, 
                        'name'        => $playerName, 
                        'raw_data'    => $apiData     
                    ]
                ]);
            }

            $errorData = $response->json();
            return response()->json([
                'message' => $errorData['message']['message'] ?? $errorData['error']['message'] ?? 'API Rejected', 
                'error' => $errorData
            ], 400);

        } catch (\Throwable $e) {
            return response()->json(['message' => 'Error', 'error' => $e->getMessage()], 500);
        }
    }