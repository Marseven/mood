<?php
class VideoController extends Controller {
    public function before()
    {
        parent::before(); // TODO: Change the autogenerated stub
        $this->activeMenu = "videos";
    }

    public function index() {
        $page = $this->request->segment(1, 'overview');
        $this->useBreadcrumbs = false;
        switch ($page) {
            case 'latest':
                $content = $this->view('video::list/lists', array('type' => 'latest', 'typeid' => ($this->request->input('premium')) ? 1 : ''));
                break;
            case 'top':
                $content = $this->view('video::list/lists', array('type' => 'top', 'typeid' => null));
                break;
            case 'category':
                $content = $this->view('video::list/lists', array('type' => 'category', 'typeid' => $this->request->segment(2)));
                break;

            default:
                $this->setTitle(l('videos'));
                $content = $this->view('video::list/overview');
                break;
        }
        return $this->render($this->view('video::list/layout', array('content' => $content, 'page' => $page)), true);
    }

    public function paginate() {
        $data = perfectUnserialize($this->request->input('data'));
        $type = $data['type'];
        $typeId = $data['typeId'];
        $offset = $this->request->input('offset');
        $limit = config('video-limit', 20);
        $theOffset = ($offset == 0) ? config('video-limit', 20) : $offset;
        $newOffset = $theOffset + $limit;

        $videos = $this->model('video::video')->getVideos($type, $typeId, $theOffset, $limit);

        $content =  $this->view('video::list/paginate', array('videos' => $videos));
        $result = array(
            'content' => $content,
            'offset' => $newOffset
        );
        return json_encode($result);
    }

    public function upload() {
        $this->setTitle(l('upload-video'));
        $this->addBreadCrumb(l('videos'), url('videos'));
        $page = $this->request->segment(1, 'upload');
        $this->addBreadCrumb(($page == 'upload') ? l('upload') : l('import'));
        $this->collapsed = true;
        if (!$this->model('video::video')->canAddVideo()) return $this->request->redirectBack();

        if ($page == 'upload' and !config('enable-video-upload', true)) return $this->request->redirect(url('videos'));
        if ($page == 'import' and !config('enable-video-import', true)) return $this->request->redirect(url('videos'));
        if ($val  = $this->request->input('val')) {
            $validator = Validator::getInstance()->scan($val, array(
                'title' => 'required'
            ));

            if ($validator->passes()) {
                if ($videoArt = $this->request->inputFile('img')) {
                    $artUpload = new Uploader($videoArt);
                    $artUpload->setPath("videos/".$this->model('user')->authId.'/art/'.date('Y').'/');
                    if ($artUpload->passed()) {
                        $val['art'] = $artUpload->resize()->result();
                    } else {
                        return json_encode(array(
                            'message' => $artUpload->getError(),
                            'type' => 'error'
                        ));
                    }
                }

                if ($page == 'upload') {


                    if ($videoFile = $this->request->inputFile('video_file')) {
                        $uploader = new Uploader($videoFile, 'video');
                        $fileSizes = $uploader->sourceSize;
                        if(config('enable-premium', false)) {
                            $usedSpace = $this->model('user')->getTracksSpace() + $fileSizes;
                            $allowSize = $this->model('user')->getTotalTrackSize() * 1024 * 1000;
                            if ($usedSpace > $allowSize) {
                                //use have used up your allowed space
                                return json_encode(array(
                                    'message' => l('not-enough-space'),
                                    'type' => 'error'
                                ));
                            }
                        }
                        $uploader->setPath("videos/".$this->model('user')->authId.'/'.date('Y').'/');
                        if ($uploader->passed()) {
                            include_once(path('app/vendor/james-heinrich/getid3/getid3/getid3.php'));
                            $tmpMove = $uploader->uploadFile()->result();
                            $getID3 = new getID3;
                            $ThisFileInfo = $getID3->analyze(path($tmpMove));
                            $val['duration'] = $this->model('track')->formatDuration($ThisFileInfo['playtime_seconds']);
                            $ext = get_file_extension($tmpMove);
                            if (config('ffmpeg-path', '')) {
                                $output_file = preg_replace('/\.'.preg_quote($ext, '$/').'/i', '.mp4', $tmpMove);
                                exec('"'.config('ffmpeg-path').'" -y -i "'.path($tmpMove).'" -vcodec libx264 -preset -filter:v scale=1280:-2 -crf 26 "'.path($output_file).'" 2>&1');
                                if ($ext !== 'mp4') delete_file(path($tmpMove));
                                $tmpMove = $output_file;
                                $total_seconds = ffmpeg_duration(path($tmpMove));
                                $duration = (int) ($total_seconds > 10) ? 11 : 5;
                                if(!isset($val['art'])) {
                                    $thumbnail_dir = 'uploads/video/photos/';
                                    $thumbnail_file = md5($val['title'].time()).'.jpg';
                                    @mkdir(path($thumbnail_dir), 0777, true);
                                    $thumbnail_path = $thumbnail_dir.$thumbnail_file;
                                    exec('"'.config('ffmpeg-path').'" -ss 5 -i  "'.path($tmpMove).'" -qscale:v 4 -frames:v 1 '.path($thumbnail_path).'');
                                    $val['art'] = $thumbnail_path;

                                }
                            }
                            $val['video'] = $this->uploadFile($tmpMove);
                        } else {
                            return json_encode(array(
                                'message' => $uploader->getError(),
                                'type' => 'error'
                            ));
                        }
                    } else {
                        return json_encode(array(
                            'message' => l('please-select-avideo'),
                            'type' => 'error'
                        ));
                    }

                } else {
                    //its import
                    if (!isset($val['art'])) {
                        if ($val['video_art']) {
                            $avatar = $val['video_art'];
                            $uploader = new Uploader($avatar, 'image', false, true, true);
                            if($uploader->passed()) {
                                $uploader->setPath($this->model('user')->authId.'/'.date('Y').'/photos/profile/');
                                $val['art'] = $uploader->resize()->result();
                            }
                        }

                    }
                }

                $videoId = $this->model('video::video')->addVideo($val);
                $video = $this->model('video::video')->find($videoId);
                return json_encode(array(
                    'status' => 1,
                    'type' => 'url',
                    'value' => $this->model('video::video')->videoUrl($video),
                    'message' => l('video-added-success')
                ));
            } else {
                return json_encode(array(
                    'message' => $validator->first(),
                    'type' => 'error'
                ));
            }
        }

        return $this->render($this->view('video::upload/index', array('page' => $page)), true);

    }

    public function fetch() {
        $link = $this->request->input('url');

        $type = null;
        $thumbnail = null;
        $duration = null;
        $title = null;
        $description = null;
        $tags = null;
        if (preg_match('%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/ ]{11})%i', $link, $match)) {
            $link = $match[1];
            $type = "youtube";
            try {
                include path('app/vendor/youtube-sdk/vendor/autoload.php');
                $youtube = new Madcoda\Youtube(array('key' => config('youtube-api-key','AIzaSyBTwxpmr5EraHyEolTDPiSx7axFS-0CE5s')));
                $get_videos = $youtube->getVideoInfo($link);
                if (!empty($get_videos)) {
                    if (!empty($get_videos->snippet)) {
                        if (!empty($get_videos->snippet->thumbnails->maxres->url)) {
                            $thumbnail = $get_videos->snippet->thumbnails->maxres->url;
                        } else if (!empty($get_videos->snippet->thumbnails->medium->url)) {
                            $thumbnail = $get_videos->snippet->thumbnails->medium->url;
                        }
                        $info = $get_videos->snippet;
                        $title = $info->title;
                        if (!empty($get_videos->contentDetails->duration)) {
                            $duration = covtime($get_videos->contentDetails->duration);
                        }
                        $description = $info->description;
                        if (!empty($get_videos->snippet->tags)) {
                            $tags_array = array();
                            if (is_array($get_videos->snippet->tags)) {
                                foreach ($get_videos->snippet->tags as $key => $tag) {
                                    $tags_array[] = $tag;
                                }
                                $tags = implode(',', $tags_array);
                            }
                        }
                    }
                }
            }
            catch (Exception $e) {
                return json_encode(array(
                    'status' => 0,
                    'message' => $e->getMessage()
                ));
            }
        }

        else if (preg_match("#https?://vimeo.com/([0-9]+)#i", $link, $match)) {
            $link = $match[1];
            $type = "vimeo";
            $api_request = curl_get_content('http://vimeo.com/api/v2/video/' . $link . '.json');
            if (!empty($api_request)) {
                $json_decode = json_decode($api_request);
                if (!empty($json_decode[0]->title)) {
                    $title = $json_decode[0]->title;
                }
                if (!empty($json_decode[0]->description)) {
                    $description = $json_decode[0]->description;
                }
                if (!empty($json_decode[0]->thumbnail_large)) {
                    $thumbnail = $json_decode[0]->thumbnail_large;
                }
                $thumbnail = str_replace('http://', 'https://', $thumbnail);
                if (!empty($json_decode[0]->duration)) {
                    $duration = gmdate("i:s", $json_decode[0]->duration) ;
                }
                if (!empty($json_decode[0]->tags)) {
                    $tags = $json_decode[0]->tags;
                }
            }
        }

        else if (preg_match('#https?:.*?\.(mp4|mov)#s', $link, $match)) {
            $link = $match[0];
            $type = "mp4";
        }

        else if (preg_match('#https://www.dailymotion.com/video/([A-Za-z0-9]+)#s', $link, $match)) {
            $link = $match[1];
            $type = "dailymotion";
            $api_request = curl_get_content('https://api.dailymotion.com/video/' . $link . '?fields=thumbnail_large_url,thumbnail_1080_url,title,duration,description,tags');
            if (!empty($api_request)) {
                $json_decode = json_decode($api_request);
                if (!empty($json_decode->title)) {
                    $title = $json_decode->title;
                }
                if (!empty($json_decode->description)) {
                    $description = $json_decode->description;
                }
                if (!empty($json_decode->thumbnail_1080_url)) {
                    $thumbnail = $json_decode->thumbnail_1080_url;
                } else if (!empty($json_decode->thumbnail_large_url)) {
                    $thumbnail = $json_decode->thumbnail_large_url;
                }
                $thumbnail = str_replace('http://', 'https://', $thumbnail);
                if (!empty($json_decode->duration)) {
                    $duration = gmdate("i:s", $json_decode->duration);
                }
                if (is_array($json_decode->tags)) {
                    $tags_array = array();
                    foreach ($json_decode->tags as $key => $tag) {
                        $tags_array[] = $tag;
                    }
                    $tags = implode(',', $tags_array);
                }
            }

        }
        else if (preg_match('~([A-Za-z0-9]+)/videos/(?:t\.\d+/)?(\d+)~i', $link, $match) && config('enable-facebook', false)) {
            $link = $match[0];
            $type = "facebook";
            $fbID = config('facebook-key');
            $fbKey = config('facebook-secret');
            $get_access_token = json_decode(curl_get_content("https://graph.facebook.com/oauth/access_token?client_id={$fbID}&client_secret={$fbKey}&grant_type=client_credentials"));
            if (!empty($get_access_token->access_token)) {
                $video_import_id_ = substr($link, strrpos($link, '/' )+1);
                $get_video_info = json_decode(curl_get_content("https://graph.facebook.com/{$video_import_id_}?fields=format,source,description,length", array('bearer' => $get_access_token->access_token)), true);
                foreach ($get_video_info['format'] as $key => $value) {
                    if ($value['filter'] == 'native') {
                        $thumbnail = $value['picture'];
                    }
                }
                $title = $get_video_info['description'];
                $duration = gmdate("i:s", $get_video_info['length']);
            } else {
                return json_encode(array(
                    'status' => 0,
                    'message' => $get_access_token->error->message
                ));
            }
        }
        if (!$type) return json_encode(array(
            'status' => 0,
            'message' => l('video-url-not-supported')
        ));

        return json_encode(array(
            'status' => 1,
            'title' => $title,
            'description' => $description,
            'tags' => $tags,
            'duration' => $duration,
            'type' => $type,
            'link' => $link,
            'thumbnail' => $thumbnail
        ));

    }

    public function page() {
        $slug = $this->request->segment(1);
        $video = $this->model('video::video')->findBySlug($slug);
        if (!$video) return $this->request->redirect(url('videos'));

        $this->addBreadCrumb(l('videos'), url('videos'));
        $page = $this->request->segment(2, '');
        $this->addBreadCrumb($video['title'], ($page) ? $this->model('video::video')->videoUrl($video) : '');

        $headerContent = '<meta property="og:image" content="'.$this->model('video::video')->getArt($video, 600).'"/>';
        $headerContent .= '<meta property="og:title" content="'.$video['title'].'"/>';
        $headerContent .= '<meta property="og:url" content="'.$this->model('video::video')->videoUrl($video).'"/>';
        $headerContent .= '<meta property="og:description" content="'.$video['description'].'"/>';
        $this->addHeaderContent($headerContent);

        if ($page == 'edit') {
            if ($video['userid'] != $this->model('user')->authId) return $this->request->redirect($this->model('video')->videoUrl($video));
            $this->addBreadCrumb(l('edit'));
            if ($val = $this->request->input('val')) {
                $validator = Validator::getInstance()->scan($val, array(
                    'title' => 'required'
                ));

                if ($validator->passes()) {
                    if ($videoArt = $this->request->inputFile('img')) {
                        $artUpload = new Uploader($videoArt);
                        $artUpload->setPath("videos/" . $this->model('user')->authId . '/art/' . date('Y') . '/');
                        if ($artUpload->passed()) {
                            $val['art'] = $artUpload->resize()->result();
                        } else {
                            return json_encode(array(
                                'message' => $artUpload->getError(),
                                'type' => 'error'
                            ));
                        }
                    }

                    $this->model('video::video')->addVideo($val, $video);

                    return json_encode(array(
                        'status' => 1,
                        'type' => 'url',
                        'value' => $this->model('video::video')->videoUrl($video),
                        'message' => l('video-saved-success')
                    ));
                } else {
                    return json_encode(array(
                        'message' => $validator->first(),
                        'type' => 'error'
                    ));
                }
            }
            return $this->render($this->view('video::page/edit', array('video' => $video)), true);
        } elseif ($page == 'delete') {
            if ($video['userid'] != $this->model('user')->authId) return json_encode(array(
                'type' => 'error',
                'message' => l('permission-denied')
            ));

            $this->model('video::video')->delete($video['id']);
            return json_encode(array(
                'status' => 1,
                'type' => 'url',
                'value' => url('videos'),
                'message' => l('video-deleted-success')
            ));
        }
        $this->model('video::video')->updateViews($video);
        return $this->render($this->view('video::page/index', array('video' => $video)), true);
    }

    public function play() {
        $videoId = $this->request->input('id');
        $this->model('video::video')->addPlays($videoId);
    }

    public function reload() {
        $videos = model('video::video')->getSuggestedVideos();
        foreach($videos as $video) {
            echo $this->view('video::display/side', array('video' => $video));
        }
    }

    public function addLater() {
        $id = $this->request->input('id');

        $add = $this->model('video::video')->addLater($id);

        return json_encode(array(
            'type' => 'function',
            'message' => ($add) ? l('add-watch-success') : l('remove-watch-success'),
            'value' => 'addWatchLater',
            'content' => json_encode(array(
                'add' => l('add-to-watch-later'),
                'remove' => l('remove-from-watch-later'),
                'which' => ($add) ? 1 : 0,
            ))
        ));
    }

    public function later() {
        $this->setTitle(l('watch-later'));
        $this->addBreadCrumb(l('videos'), url('videos'));
        $this->addBreadCrumb(l('watch-later'));
        $this->activeMenu = "watch-later";
        return $this->render($this->view('video::collection/later'), true);
    }

    public function history() {
        $this->setTitle(l('watch-history'));
        $this->addBreadCrumb(l('videos'), url('videos'));
        $this->addBreadCrumb(l('watch-history'));
        $this->activeMenu = "watch-history";
        return $this->render($this->view('video::collection/history'), true);
    }
}