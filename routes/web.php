<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'DyzzStore API is running.',
        'hint' => 'Use the /api/topup and /api/admin endpoints from your React frontend.',
    ]);
});
Route::get('/update-game-ids', function () {
    try {
        // 🎯 ១. សម្រាប់ Mobile Legends ធម្មតា
        DB::table('topup_games')->where('code', 'mlbb')->update(['api_game_id' => 3]);

        // 🎯 ២. សម្រាប់ Mobile Legends Exclusive
        DB::table('topup_games')->where('code', 'mlbb_exclusive')->update(['api_game_id' => 5]);

        // 🎯 ៣. សម្រាប់ Magic Chess Gogo (បន្ថែមថ្មីផ្អែកលើរូបភាពនេះ)
        DB::table('topup_games')
            ->where('code', 'magic_chest_gogo') // ⚠️ ឆែកមើលអក្សរ code ក្នុង DB បងមើល ក្រែងលោបងសរសេរ 'magic_chess_gogo' (អក្សរ s)
            ->update(['api_game_id' => 107]);

        return "🎉 Successfully updated Game IDs on Aiven! (MLBB = 3, Exclusive = 5, Magic Chess Gogo = 107)";
    } catch (\Exception $e) {
        return "⚠️ Error: " . $e->getMessage();
    }
});