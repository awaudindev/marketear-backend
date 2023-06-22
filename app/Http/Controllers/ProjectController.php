<?php

namespace App\Http\Controllers;

use App\Jobs\SrapeSource;
use App\Models\CampaignSource;
use App\Models\Creator;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Services\ProjectService;
use App\Models\Intent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProjectController extends Controller
{
    protected $projectService;

    public function __construct(ProjectService $projectService)
    {
        $this->projectService = $projectService;
    }

    public function getProject ()
    {
        if (Auth::user()->role_id == 1) {
            $data = Project::with('category','channels','urls','sources')->get();
        } else {
            $data = Project::where('user_id',Auth::user()->id)->with('category','channels','urls','sources')->get();
        }
        return response()->json($data,200);
    }

    public function getDetail ($id)
    {
        $data = Project::with('category','channels','urls','sources')->find($id);
        return response()->json($data,200);
    }

    public function getCreator ($username)
    {
        $data = Creator::where('username','=',$username)->first();
        return response()->json($data, 200);
    }

    public function getCategory ()
    {
        $data = ProjectCategory::get();
        return response()->json($data, 200);
    }

    public function create (Request $request)
    {
        $validated_array = [
            'name' => 'required',
            'type' => 'required',
            'category' => 'required|numeric',
        ];

        $validated = Validator::make($request->all(), $validated_array);
        
        if ($validated->fails()) {
            return response()->json($validated->errors(), 500);
        } else {
            try {
                DB::beginTransaction();
                $space = \App\Models\User::find(Auth::id())->space;
                if (!$space) {
                    $space = \App\Models\Project::create([
                        'name' => 'Main Space',
                        'user_id' => Auth::id()
                    ]);
                }
                $workspace = $this->projectService->createProject($request->all(), Auth::user());
                DB::table('spaces_relation')->insert([
                    'name' => 'workspace',
                    'space_id' => $space->id,
                    'relation_id' => $workspace->id
                ]);
                if ($workspace && $workspace->type == 'campaign') {
                    // $queue = new SrapeSource($workspace->id);
                    // $this->dispatch($queue);
                }
                
                DB::commit();
                return response()->json([
                    'status' => true,
                    'message' => $workspace
                ], 200);
            } catch (\Exception $e) {
                Log::info(\App\Models\User::find(Auth::id())->id);
                return response()->json($e->getMessage(), 500);
            }
    
        }
    }

    public function update (Request $request, $id)
    {
        $validated_array = [
            'name' => 'required',
            'category' => 'required|numeric',
        ];
        $validated = Validator::make($request->all(), $validated_array);
        if ($validated->fails()) {
            return response()->json($validated->errors(), 500);
        } else {
            try {
                $request['category_id'] = $request['category'];
                $workspace = Project::findOrFail($id);
                $workspace->update($request->all());

                return response()->json([
                    'status' => true,
                    'message' => $workspace
                ], 200);
            } catch (\Exception $e) {
                return response()->json($e->getMessage(), 500);
            }
    
        }
    }

    public function updateIntent (Request $request, $id)
    {
        $validated_array = [
            'sentiment' => 'required',
        ];
        $validated = Validator::make($request->all(), $validated_array);
        if ($validated->fails()) {
            return response()->json($validated->errors(), 500);
        } else {
            try {
                $comment = Intent::find($id);
                $comment->sentiment = $request->sentiment;
                $comment->save();

                return response()->json([
                    'status' => true,
                    'message' => $comment
                ], 200);
            } catch (\Exception $e) {
                return response()->json($e->getMessage(), 500);
            }
    
        }
    }

    public function deleteProject ($id)
    {
        try {
            $workspace = Project::findOrFail($id);
            $workspace->delete();

            return response()->json([
                'status' => true,
                'message' => $workspace
            ], 200);
        } catch (\Exception $e) {
            return response()->json($e->getMessage(), 500);
        }
    }    
 
    public function createUrl (Request $request)
    {
        $validated_array = [
            'id' => 'required',
            'type' => 'required',
            'url' => 'required'
        ];

        $validated = Validator::make($request->all(), $validated_array);
        
        if ($validated->fails()) {
            return response()->json($validated->errors(), 500);
        } else {
            try {
                $workspace = $this->projectService->createUrl($request->all(), $request->id);

                if ($workspace && $request->type == 'campaign') {
                    $queue = new SrapeSource($request->id);
                    $this->dispatch($queue);
                }

                return response()->json([
                    'status' => true,
                    'message' => $workspace
                ], 200);
            } catch (\Exception $e) {
                return response()->json($e->getMessage(), 500);
            }
    
        }
    }

    public function reintent (Request $request)
    {
        if ($request->id) {
            $workspace = Project::find($request->id);
            $detail = [];
            try{
                if (!count($workspace->sources)) {
                $createUrl = $this->projectService->createUrl(
                    ['url' => $workspace->urls->pluck('url'),
                    'type' => $workspace->type], 
                    $request->id);
                } else {
                    $createUrl = $workspace->sources;
                }
                if ($createUrl && $workspace->type == 'campaign') {
                    $queue = new SrapeSource($request->id);
                    $this->dispatch($queue);
                }
                
                foreach($workspace->sources as $each) {
                    foreach ($each->intents as $item) {
                        if ($item->sentiment == 'Neutral') {
                        $predict = Http::withHeaders(['Content-Type' => 'application/json'])
                                ->send('POST', env("ML_URL", 'http://localhost:5000')."/api/test-predict", [
                                    'body' => '{ "text": "'.$item->text.'" }'
                                ])->json();
            
                        // if ($predict->failed() || $predict->clientError() || $predict->serverError()) {
                        //     $predict->throw()->json();
                        // }
                            if ($predict) {
                                array_push($detail,$predict['label']);
                                $comment = \App\Models\Intent::find($item->id);
                                $comment->sentiment = $predict['label'];
                                $comment->score = $predict['score'];
                                $comment->save();
                            }
                        }
                    }
                }
                return response()->json([
                    'status' => true,
                    'message' => $detail
                ], 200);
            } catch (\Exception $e) {
                Log::info($e);
                return response()->json($e, 500);
            }
        } else {
            return response()->json('no id', 500);
        }
    }

    public function recreator (Request $request)
    {
        if ($request->id) {
            try{
                $workspace = Project::find($request->id);
                foreach($workspace->urls as $each) {
                    if(strpos($each->url, 'tiktok')) {
                        $sourceId = explode('/', $each->url)[5];
                        $username = trim(explode('/', $each->url)[3], '@');
                        $creator = $this->projectService->_checkCreator($username,$each->channel_id);
                        $source = CampaignSource::where('project_id','=',$request->id)
                        ->where('url','=',$sourceId)->first();
                        $source->creator_id = $creator ? $creator->id : 0;
                        $source->save();
                    }
                }
                return response()->json('finish', 200);
            } catch (\Exception $e) {
                return response()->json($e->getMessage(), 500);
            }
        } else {
            return response()->json('no id', 500);
        }
    }
}
