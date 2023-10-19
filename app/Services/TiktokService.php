<?php 

namespace App\Services;

use App\Models\CampaignSource;
use App\Models\Creator;
use App\Models\Intent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Exception;

class TiktokService 
{
    public function tiktokScrape($data)
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
            while($cursor < $target) {
                $url = "https://scraptik.p.rapidapi.com/list-comments?aweme_id=".$data['url']."&count=$per_page&cursor=$cursor";
                
                $curl = curl_init();
                curl_setopt_array($curl, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_ENCODING => "",
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 400,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => "GET",
                    CURLOPT_HTTPHEADER => [
                        "X-RapidAPI-Host: scraptik.p.rapidapi.com",
                        "X-RapidAPI-Key: ".env('API_ID_KEY')
                    ],
                ]);
        
                $response = curl_exec($curl);
                $err = curl_error($curl);
        
                curl_close($curl);
                if ($err) {
                    LogService::record([
                        'request' => json_encode($response),
                        'response' => json_encode($err),
                        'url' => $url,
                    ]);
                    return false;
                }

                $comments = json_decode($response);

                foreach($comments->comments as $c) {
                    if (isset($c->user)) {
                        $exist = Intent::where('campaign_source_id',$data['id'])
                            ->where('nickname',$c->user->nickname)
                            ->where('cid', $c->cid)->first();
                           if (!$exist) { 
                                $intent = Intent::create(
                                    [
                                        'campaign_source_id' => $data['id'], 
                                        'nickname' => $c->user->nickname, 
                                        'region' => $c->user->region,
                                        'language' => $c->user->language,
                                        'picture' => $c->user->avatar_thumb->url_list[0],
                                        'text' => substr($c->text,0,254),
                                        'cid' => $c->cid,
                                        'sentiment' => 'Neutral',
                                        'score' => 0,
                                        'comment_at' => date('Y/m/d H:i:s', $c->create_time)
                                    ]
                                );
                                array_push($result,[
                                    'text' => $c->text,
                                    'id' => $intent->id
                                ]);
                            }

                        if (isset($c->reply_comment) && count($c->reply_comment)) {
                            foreach($c->reply_comment as $d){
                                $exist = Intent::where('campaign_source_id',$data['id'])
                                    ->where('nickname',$d->user->nickname)
                                    ->where('cid', $d->cid)->first();
                                if (!$exist) { 
                                        $intent = Intent::create(
                                            [
                                                'campaign_source_id' => $data['id'], 
                                                'nickname' => $d->user->nickname, 
                                                'region' => $d->user->region,
                                                'language' => $d->user->language,
                                                'picture' => $d->user->avatar_thumb->url_list[0],
                                                'text' => substr($c->text,0,254),
                                                'cid' => $d->cid,
                                                'sentiment' => 'Neutral',
                                                'score' => 0,
                                                'comment_at' => date('Y/m/d H:i:s', $d->create_time)
                                            ]
                                        );
                                        array_push($result,[
                                            'text' => $d->text,
                                            'id' => $intent->id
                                        ]);
                                    }
                            }
                        }
                    }
                }
                $cursor+= $per_page;
            }

            // UPDATE CAMPAIGN SOURCE ID
            CampaignSource::where('id', $data['id'])->update(['is_scraped' => 1]);
        } catch (Exception $e) {
            LogService::record([
                'request' => "",
                'response' => json_encode($e),
                'url' => "https://scraptik.p.rapidapi.com/list-comments?aweme_id=".$data['url']."&count=$per_page&cursor=$cursor",
            ]);
            return [];
        }

        return $result;

    }

    private function _postDetail($data)
    {
        $url = "https://scraptik.p.rapidapi.com/get-post?aweme_id=".$data['url'];
        // $url = "https://simpliers.p.rapidapi.com/api/get/tiktok/mediaInfo?media_id=".$data['url'];
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
                    "X-RapidAPI-Host: scraptik.p.rapidapi.com",
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
        if (isset($res)) {
            $creator = Creator::where(['username' =>  $res->aweme_detail->author->unique_id, 'channel_id' => 1])->first();
            if (!$creator) {
                $creator = $this->register($res->aweme_detail->author->unique_id, 1);
            }
            if ($creator) $source->creator_id = $creator->id;
            $source->comment_count = $res->aweme_detail->statistics->comment_count;
            $source->collect_count = 0;
            $source->like_count = $res->aweme_detail->statistics->digg_count;
            $source->play_count = $res->aweme_detail->statistics->play_count;
            $source->share_count = $res->aweme_detail->statistics->share_count ?? 0;
            $source->other_share_count = $res->aweme_detail->statistics->whatsapp_share__count ?? 0;
            $source->caption = substr($res->aweme_detail->desc, 0, 254);
            $source->thumbnail = $res->aweme_detail->video->cover->url_list[0];
            $source->created_at = \Carbon\Carbon::parse($res->aweme_detail->create_time);
        }
        $source->save();
                    
        return $source;
        
    }

    static public function register($username, $channelId)
    {
        try {

            $curl = curl_init();
    
            curl_setopt_array($curl, [
                CURLOPT_URL => "https://scraptik.p.rapidapi.com/get-user?username=$username",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "GET",
                CURLOPT_HTTPHEADER => [
                    "X-RapidAPI-Host: scraptik.p.rapidapi.com",
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
                    'url' => "https://scraptik.p.rapidapi.com/get-user?username=$username",
                ]);
            }


            if (!$response) {
                return false;
            }
            $res = json_decode($response);
            Log::info($response);
            // dd($res->userInfo);
            if (isset($res->user) && isset($res->user->nickname)) {
                $creator = Creator::create([
                    'name' => $res->user->nickname,
                    'username' => $res->user->unique_id,
                    'thumbnail' => $res->user->avatar_thumb->url_list[1],
                    'signature' => $res->user->signature,
                    'stats' => json_encode([
                        'follower_count' => $res->user->follower_count,
                        'following_count' => $res->user->following_count
                    ]),
                    'channel_id' => $channelId
                ]);
                
                return $creator;
            } else {
                return false;
            }
        } catch (Exception $e) {
            LogService::record([
                'request' => "",
                'response' => json_encode($e),
                'url' => "https://scraptik.p.rapidapi.com/get-user?username=$username",
            ]);
        }
    }
}