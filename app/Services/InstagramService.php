<?php 

namespace App\Services;

use App\Models\CampaignSource;
use App\Models\Creator;
use App\Models\Intent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Exception;

class InstagramService 
{
    public function instagramScrape($data)
    {
        // $post = CampaignSource::where('url', $data['url'])->first();
        if($data['is_scraped'] == 1) {
            $sour = Intent::where('campaign_source_id', $data['id'])->whereNull('sentiment')->get()->map(function($q) {
                return [
                    'text' => $q->text,
                    'id' => $q->id
                ];
            })
            ->toArray();
            
            Log::info($data);
            return $sour;
        }
        $plan = Auth::user()->subscriptions;
        if (count($plan)) $features = Auth::user()->subscription($plan[0]['tag'])->features;

        $data = $this->_postDetail($data);

        if (!$data) {
            return false;
        }
        $target = count($plan) ? (int)$features[0]->value : 100;
        $per_page = 10;
        $cursor = 0;
        $result = [];
        try {
            $url = "https://simpliers.p.rapidapi.com/api/get/instagram/mediaComments?cursor=0&media_id=".$data['url'];

            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 3000,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "GET",
                CURLOPT_HTTPHEADER => [
                    "X-RapidAPI-Host: simpliers.p.rapidapi.com",
                    "X-RapidAPI-Key: ".env('API_ID_KEY')
                ],
            ]);
    
            $response = curl_exec($curl);
            $err = curl_error($curl);
    
            curl_close($curl);
    
            if ($err) {
                LogService::record([
                    'request' => "",
                    'response' => json_encode($err),
                    'url' => $url,
                ]);
                return false;
            }
            if(!$response) {
                return false;
            }

            $comments = json_decode($response);
            foreach($comments->entries as $c) {
                if (isset($c) && isset($c->id)) {
                    $exist = Intent::where('campaign_source_id',$data['id'])
                        ->where('nickname',$c->owner_username)
                        ->where('comment_at', $c->created_at)->first();
                       if (!$exist) { 
                        $intent = Intent::create(
                            [
                                'campaign_source_id' => $data['id'], 
                                'nickname' => $c->owner_username, 
                                'region' => null,
                                'language' => null,
                                'picture' => $c->owner_profile_picture,
                                'text' => mb_convert_encoding($c->text, "UTF-8"),
                                'cid' => null,
                                'sentiment' => 'Neutral',
                                'score' => 0,
                                'comment_at' => $c->created_at 
                            ]);
                        array_push($result,[
                            'text' => $c->text,
                            'id' => $intent->id
                        ]);
                    }
                }
            }
            $cursor+= $per_page;
        } catch (Exception $e) {
            LogService::record([
                'request' => "error",
                'response' => $e,
                'url' => "https://simpliers.p.rapidapi.com/api/get/instagram/mediaComments?media_id=".$data['url'],
            ]);
            return [];
        }

        // UPDATE CAMPAIGN SOURCE ID
        CampaignSource::where('id', $data['id'])->update(['is_scraped' => 1]);

        return $result;

    }

    private function _postDetail($data)
    {
        // $url = "https://scraptik.p.rapidapi.com/get-post?aweme_id=".$data['url'];
        $url = "https://simpliers.p.rapidapi.com/api/get/instagram/mediaInfo?media_id=".$data['url'];
        try {
            $curl = curl_init();
    
            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "GET",
                CURLOPT_HTTPHEADER => [
                    "X-RapidAPI-Host: simpliers.p.rapidapi.com",
                    "X-RapidAPI-Key: ".env('API_ID_KEY')
                ],
            ]);
    
            $response = curl_exec($curl);
            $err = curl_error($curl);
    
            curl_close($curl);
            Log::info($url);
            if ($err) {
                LogService::record([
                    'request' => "",
                    'response' => json_encode($err),
                    'url' => $url,
                ]);
                return false;
            }
        } catch (Exception $e) {
            LogService::record([
                'request' => "",
                'response' => json_encode($e),
                'url' => $url,
            ]);
            return false;
        }

        if (!$response) {
            return false;
            // // return response()->json([
            //     'status' => false,
            // ]);
        }
        $res = json_decode($response);
        
        Log::info($response);
        $source = CampaignSource::where('id', $data['id'])->first();
        if (isset($res) && isset($res->id)) {
            $creator = Creator::where(['username' =>  $res->owner->username, 'channel_id' => 2])->first();
            if (!$creator) {
                $creator = $this->register($res->owner->username, 2);
            }
            $source->creator_id = $creator->id;
            $source->comment_count = $res->comments_count;
            $source->collect_count = 0;
            $source->like_count = $res->likes_count;
            $source->play_count = $res->views_count;
            $source->share_count = $res->retweet_count ?? 0;
            $source->other_share_count = $res->quote_count ?? 0;
            $source->caption = substr($res->caption, 0, 254);
            $source->thumbnail = $res->preview_url;
            $source->created_at = \Carbon\Carbon::parse($res->created_at);
        }
        $source->save();
                    
        return $source;
        
    }

    static public function register($username, $channelId)
    {
        try {

            $curl = curl_init();
    
            curl_setopt_array($curl, [
                CURLOPT_URL => "https://simpliers.p.rapidapi.com/api/get/instagram/userInfo?username=$username",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "GET",
                CURLOPT_HTTPHEADER => [
                    "X-RapidAPI-Host: simpliers.p.rapidapi.com",
                    "X-RapidAPI-Key: ".env('API_ID_KEY')
                ],
            ]);
    
            $response = curl_exec($curl);
            $err = curl_error($curl);
    
            curl_close($curl);
            if ($err) {
                LogService::record([
                    'request' => "",
                    'response' => json_encode($err),
                    'url' => "https://simpliers.p.rapidapi.com/api/get/instagram/userInfo?username=$username",
                ]);
            }
        } catch (Exception $e) {
            LogService::record([
                'request' => "",
                'response' => json_encode($e),
                'url' => "https://simpliers.p.rapidapi.com/api/get/instagram/userInfo?username=$username",
            ]);
        }


        if (!$response) {
            return false;
        }
        $res = json_decode($response);
        // dd($res->userInfo);
        if (isset($res) && isset($res->id)) {
            $creator = Creator::create([
                'name' => $res->name,
                'username' => $res->username,
                'thumbnail' => $res->profile_picture,
                'signature' => $res->bio,
                'stats' => json_encode($res),
                'channel_id' => $channelId
            ]);
            
            return $creator;
        } else {
            return false;
        }
    }
}