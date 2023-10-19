<?php

namespace App\Services;

use App\Models\CampaignSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SrapeService 
{

    protected $tiktokService;
    protected $instagramService;
    public function __construct(TiktokService $tiktokService, InstagramService $instagramService)
    {
        $this->tiktokService = $tiktokService;
        $this->instagramService = $instagramService;
    }
    public function scrape($workspaceId = 79)
    {
        // $source = CampaignSource::where("campaign_id", $campaignId)->with('channel')->get();
        $source = CampaignSource::where("project_id", $workspaceId)->with('channel')->get();
        if (count($source) < 1) {
            return Log::info("Theres no url to scrape");
        }
        $intent = [];
        $response = [];
        foreach($source as $url) {
            // switch($url->channel->name) {
            //     case 'tiktok':
            if ($url->channel->name == 'tiktok') {
                Log::info('get tiktok comment');
                $response = $this->tiktokService->tiktokScrape($url);
            } else if ($url->channel->name == 'instagram') {
                Log::info('get ig comment');
                $response = $this->instagramService->instagramScrape($url);
            }
                // break;
                // case 'instagram':
                //     $response = $this->tiktokService->tiktokScrape($url);
                // break;
            // }
            if (is_array($response) && count($response)) $intent = array_merge($intent, $response);
        }
        
        if(!$intent || (is_array($intent) && count($intent) < 1)) {
            Log::info($intent);
            return response()->json([
                'status' => false,
            ], 500);
        }

        // $predict = Http::post(env("ML_URL", 'http://localhost:5000')."/api/predict", $intent);

        // if ($predict->failed() || $predict->clientError() || $predict->serverError()) {
        //     $predict->throw()->json();
        // }

        foreach ($intent as $item) {
            // $predict = Http::withHeaders(['Content-Type' => 'application/json'])
            //         ->send('POST', env("ML_URL", 'http://localhost:5000')."/api/test-predict", [
            //             'body' => '{ "text": "'.$item['text'].'" }'
            //         ])->json();

            // if ($predict->failed() || $predict->clientError() || $predict->serverError()) {
            //     $predict->throw()->json();
            // }

            // if ($predict) {
            //     $comment = \App\Models\Intent::find($item['id']);
            //     $comment->sentiment = $predict['label'];
            //     $comment->score = $predict['score'];
            //     $comment->save();
            // }
        }

        return response()->json([
            'status' => true,
        ], 200);

        
    }
}