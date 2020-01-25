    public function updateTimeline($contact_id, $userid = null)
    {
        $pageToken = null;
        if ($userid == null) $userid = Auth::user()->id;

        $user = User::where('id', $userid)->first();
        $relationship = Relationship::where('id', $contact_id)->first();

        $last_sync_date = ($relationship->last_timeline_sync != null) ? date('Y/m/d', (strtotime($relationship->last_timeline_sync) - (60 * 60 * 24))) : date('Y', strtotime('-1 year')) . "/01/01";


        $email = $relationship->email;
        $contact = Contacts::where('userid', $userid)->where('email', $email)->first();
        $google_api = googleApi::where('userid', $userid)->where('id', $contact->google_id)->first();
        $user_email = $google_api->email;


        $opt_param['q'] = "(from:$email OR to:$email) after:$last_sync_date";

        $client = $this->SetupClient($google_api->email);
        $service = new Google_Service_Gmail($client);

        do {
            try {
                if (!empty($pageToken)) {
                    $opt_param['pageToken'] = $pageToken;
                }

                $get_first_interaction_message = @$service->users_messages->listUsersMessages('me', $opt_param);


                if ($get_first_interaction_message->getMessages()) {
                    $messages = $get_first_interaction_message->getMessages();
                    $pageToken = $get_first_interaction_message->getNextPageToken();
                    for ($i = 0; $i < count($messages); $i++) {

                        $check_timeline = Timeline::where('extra_variable', $messages[$i]->getId())->where('userid', $userid)->count();

                        if ($check_timeline == 0) {

                            $message = $service->users_messages->get('me', $messages[$i]->getId(), array('format' => 'full'));

                            $headers = $message->getPayload()->getHeaders();
                            $from = null;
                            $message_date = null;
                            $desp = "";

                            foreach ($headers as $single) {
                                if ($single->getName() == 'From') {
                                    preg_match('/<(.*?)>/', $single->getValue(), $match);
                                    if (isset($match[1])) {
                                        $from = $match[1];
                                    } else {
                                        $from = $single->getValue();
                                    }
                                } else if ($single->getName() == 'Date') {
                                    $message_date_time = strtotime($single->getValue());
                                    $message_date = date('Y-m-d h:i:s', $message_date_time);
                                } else if ($single->getName() == 'Subject') {
                                    $desp = $single->getValue();
                                }
                            }
                            $subject = ($from != null && $from == $user_email) ? "You sent an email" : "You received an email";

                            $timeline = new Timeline;

                            $timeline->subject = $subject;
                            $timeline->relationship_id = $contact_id;
                            $timeline->extra_variable = $messages[$i]->getId();
                            $timeline->userid = $user->id;
                            $timeline->description =  $desp;
                            $timeline->save();
                            $timeline->created_at = $message_date;
                            $timeline->save();
                        }
                    }
                }
            } catch (Exception $e) {
                print 'An error occurred: ' . $e->getMessage();
            }
        } while ($pageToken);

        $relationship->last_timeline_sync = date('Y-m-d');
        $relationship->save();
    }