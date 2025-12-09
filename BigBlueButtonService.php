<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BigBlueButton API Servisi
 * 
 * Big Blue Button video konferans sistemine bağlantı sağlar
 * API dokümantasyonu: https://docs.bigbluebutton.org/dev/api.html
 */
class BigBlueButtonService
{
    private $serverUrl;
    private $secret;
    private $hashAlgorithm = 'sha256'; // sha1, sha256, sha384, sha512

    public function __construct()
    {
        $this->serverUrl = rtrim(config('services.bigbluebutton.server_url'), '/');
        $this->secret = config('services.bigbluebutton.secret');
        
        Log::info('BBB Service başlatıldı', [
            'server_url' => $this->serverUrl,
            'hash_algorithm' => $this->hashAlgorithm
        ]);
    }

    /**
     * API checksum oluşturur
     * 
     * @param string $apiCall API metodunun adı (örn: 'create', 'join', 'end')
     * @param array $params Query string parametreleri
     * @return string Hesaplanmış checksum
     */
    private function generateChecksum(string $apiCall, array $params = [])
    {
        // Query string oluştur
        $queryString = http_build_query($params);
        
        // Checksum string: apiCall + queryString + secret
        $checksumString = $apiCall . $queryString . $this->secret;
        
        // Hash hesapla
        $checksum = hash($this->hashAlgorithm, $checksumString);
        
        Log::debug('BBB Checksum oluşturuldu', [
            'api_call' => $apiCall,
            'params' => $params,
            'checksum' => $checksum,
            'algorithm' => $this->hashAlgorithm
        ]);
        
        return $checksum;
    }

    /**
     * API URL'i oluşturur
     * 
     * @param string $apiCall API metodunun adı
     * @param array $params Query string parametreleri
     * @return string Tam API URL'i
     */
    private function buildUrl(string $apiCall, array $params = [])
    {
        $checksum = $this->generateChecksum($apiCall, $params);
        $params['checksum'] = $checksum;
        
        $url = $this->serverUrl . '/api/' . $apiCall . '?' . http_build_query($params);
        
        Log::debug('BBB URL oluşturuldu', [
            'api_call' => $apiCall,
            'url' => $url
        ]);
        
        return $url;
    }

    /**
     * BBB API'ye GET request gönderir
     * 
     * @param string $apiCall API metodunun adı
     * @param array $params Query string parametreleri
     * @return \SimpleXMLElement|false XML response
     */
    private function sendRequest(string $apiCall, array $params = [])
    {
        try {
            $url = $this->buildUrl($apiCall, $params);
            
            Log::info('BBB API isteği gönderiliyor', [
                'api_call' => $apiCall,
                'params' => $params,
                'url' => $url
            ]);
            
            // SSL doğrulaması devre dışı bırakıldı (withoutVerifying)
            $response = Http::withoutVerifying()->timeout(30)->get($url);
            
            if (!$response->successful()) {
                Log::error('BBB API hatası', [
                    'api_call' => $apiCall,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return false;
            }
            
            // XML parse et
            $xml = simplexml_load_string($response->body());
            
            Log::info('BBB API yanıtı alındı', [
                'api_call' => $apiCall,
                'return_code' => (string)$xml->returncode,
                'message_key' => (string)($xml->messageKey ?? ''),
                'message' => (string)($xml->message ?? '')
            ]);
            
            return $xml;
            
        } catch (\Exception $e) {
            Log::error('BBB API exception', [
                'api_call' => $apiCall,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Toplantı oluşturur
     * 
     * @param array $params Toplantı parametreleri
     * @return array Sonuç array'i
     */
    public function createMeeting(array $params)
    {
        Log::info('BBB createMeeting çağrıldı', ['params' => $params]);
        
        // Varsayılan parametreler
        $meetingParams = [
            'meetingID' => $params['meetingID'] ?? 'meeting_' . uniqid(),
            'name' => $params['name'] ?? 'Online Ders',
            'attendeePW' => $params['attendeePW'] ?? 'student_' . bin2hex(random_bytes(4)),
            'moderatorPW' => $params['moderatorPW'] ?? 'teacher_' . bin2hex(random_bytes(4)),
            'welcome' => $params['welcome'] ?? '<br>Derse hoş geldiniz!',
            'record' => $params['record'] ?? 'false',
            'autoStartRecording' => $params['autoStartRecording'] ?? 'false',
            'allowStartStopRecording' => $params['allowStartStopRecording'] ?? 'true',
            'voiceBridge' => $params['voiceBridge'] ?? rand(70000, 99999),
            'maxParticipants' => $params['maxParticipants'] ?? 50,
            'logoutURL' => $params['logoutURL'] ?? '',
            'duration' => $params['duration'] ?? 0, // 0 = sınırsız
        ];
        
        // Opsiyonel parametreler
        if (isset($params['duration'])) {
            $meetingParams['duration'] = $params['duration'];
        }
        
        Log::info('BBB Meeting parametreleri hazırlandı', ['meeting_params' => $meetingParams]);
        
        $xml = $this->sendRequest('create', $meetingParams);
        
        if (!$xml || (string)$xml->returncode !== 'SUCCESS') {
            Log::error('BBB Meeting oluşturulamadı', [
                'returncode' => $xml ? (string)$xml->returncode : 'NO_RESPONSE',
                'message' => $xml ? (string)$xml->message : 'No response from BBB server'
            ]);
            
            return [
                'success' => false,
                'error' => $xml ? (string)$xml->message : 'BBB sunucusuna bağlanılamadı',
                'meeting_id' => null,
                'moderator_pw' => null,
                'attendee_pw' => null
            ];
        }
        
        Log::info('BBB Meeting başarıyla oluşturuldu', [
            'meeting_id' => (string)$xml->meetingID,
            'internal_meeting_id' => (string)$xml->internalMeetingID,
            'parent_meeting_id' => (string)($xml->parentMeetingID ?? ''),
            'create_time' => (string)($xml->createTime ?? ''),
            'attendee_pw' => $meetingParams['attendeePW'],
            'moderator_pw' => $meetingParams['moderatorPW']
        ]);
        
        return [
            'success' => true,
            'meeting_id' => (string)$xml->meetingID,
            'internal_meeting_id' => (string)$xml->internalMeetingID,
            'parent_meeting_id' => (string)($xml->parentMeetingID ?? ''),
            'attendee_pw' => $meetingParams['attendeePW'],
            'moderator_pw' => $meetingParams['moderatorPW'],
            'voice_bridge' => (string)($xml->voiceBridge ?? $meetingParams['voiceBridge']),
            'dial_number' => (string)($xml->dialNumber ?? ''),
            'create_time' => (string)($xml->createTime ?? ''),
            'create_date' => (string)($xml->createDate ?? ''),
            'has_user_joined' => (string)($xml->hasUserJoined ?? 'false'),
            'duration' => (int)($xml->duration ?? 0),
            'has_been_forcibly_ended' => (string)($xml->hasBeenForciblyEnded ?? 'false')
        ];
    }

    /**
     * Toplantıya katılım URL'i oluşturur
     * 
     * @param string $meetingId Toplantı ID'si
     * @param string $userName Katılacak kullanıcının adı
     * @param string $password Katılım şifresi (moderator veya attendee)
     * @param bool $redirect Otomatik yönlendirme yapılsın mı
     * @return string Katılım URL'i
     */
    public function getJoinUrl(string $meetingId, string $userName, string $password, bool $redirect = true)
    {
        Log::info('BBB getJoinUrl çağrıldı', [
            'meeting_id' => $meetingId,
            'user_name' => $userName,
            'redirect' => $redirect
        ]);
        
        $params = [
            'meetingID' => $meetingId,
            'fullName' => $userName,
            'password' => $password,
            'redirect' => $redirect ? 'true' : 'false'
        ];
        
        $url = $this->buildUrl('join', $params);
        
        Log::info('BBB Join URL oluşturuldu', [
            'meeting_id' => $meetingId,
            'user_name' => $userName,
            'url' => $url
        ]);
        
        return $url;
    }

    /**
     * Toplantının çalışıp çalışmadığını kontrol eder
     * 
     * @param string $meetingId Toplantı ID'si
     * @return bool Toplantı çalışıyor mu?
     */
    public function isMeetingRunning(string $meetingId)
    {
        Log::info('BBB isMeetingRunning çağrıldı', ['meeting_id' => $meetingId]);
        
        $xml = $this->sendRequest('isMeetingRunning', ['meetingID' => $meetingId]);
        
        if (!$xml || (string)$xml->returncode !== 'SUCCESS') {
            Log::warning('BBB isMeetingRunning kontrolü başarısız', [
                'meeting_id' => $meetingId,
                'returncode' => $xml ? (string)$xml->returncode : 'NO_RESPONSE'
            ]);
            return false;
        }
        
        $isRunning = strtolower((string)$xml->running) === 'true';
        
        Log::info('BBB Meeting durumu kontrol edildi', [
            'meeting_id' => $meetingId,
            'is_running' => $isRunning
        ]);
        
        return $isRunning;
    }

    /**
     * Toplantı bilgilerini getirir
     * 
     * @param string $meetingId Toplantı ID'si
     * @param string $moderatorPw Moderator şifresi
     * @return array|false Toplantı bilgileri veya false
     */
    public function getMeetingInfo(string $meetingId, string $moderatorPw = null)
    {
        Log::info('BBB getMeetingInfo çağrıldı', ['meeting_id' => $meetingId]);
        
        $params = ['meetingID' => $meetingId];
        if ($moderatorPw) {
            $params['password'] = $moderatorPw;
        }
        
        $xml = $this->sendRequest('getMeetingInfo', $params);
        
        if (!$xml || (string)$xml->returncode !== 'SUCCESS') {
            Log::error('BBB Meeting bilgisi alınamadı', [
                'meeting_id' => $meetingId,
                'returncode' => $xml ? (string)$xml->returncode : 'NO_RESPONSE',
                'message' => $xml ? (string)$xml->message : 'No response'
            ]);
            return false;
        }
        
        // Katılımcı listesini parse et
        $attendees = [];
        if (isset($xml->attendees->attendee)) {
            foreach ($xml->attendees->attendee as $attendee) {
                $attendees[] = [
                    'user_id' => (string)$attendee->userID,
                    'full_name' => (string)$attendee->fullName,
                    'role' => (string)$attendee->role,
                    'is_presenter' => strtolower((string)$attendee->isPresenter) === 'true',
                    'is_listening_only' => strtolower((string)$attendee->isListeningOnly) === 'true',
                    'has_joined_voice' => strtolower((string)$attendee->hasJoinedVoice) === 'true',
                    'has_video' => strtolower((string)$attendee->hasVideo) === 'true',
                ];
            }
        }
        
        $meetingInfo = [
            'success' => true,
            'meeting_name' => (string)$xml->meetingName,
            'meeting_id' => (string)$xml->meetingID,
            'internal_meeting_id' => (string)$xml->internalMeetingID,
            'create_time' => (string)$xml->createTime,
            'create_date' => (string)$xml->createDate,
            'voice_bridge' => (string)$xml->voiceBridge,
            'dial_number' => (string)$xml->dialNumber,
            'attendee_pw' => (string)$xml->attendeePW,
            'moderator_pw' => (string)$xml->moderatorPW,
            'running' => strtolower((string)$xml->running) === 'true',
            'duration' => (int)$xml->duration,
            'has_user_joined' => strtolower((string)$xml->hasUserJoined) === 'true',
            'recording' => strtolower((string)$xml->recording) === 'true',
            'has_been_forcibly_ended' => strtolower((string)$xml->hasBeenForciblyEnded) === 'true',
            'start_time' => (string)($xml->startTime ?? ''),
            'end_time' => (string)($xml->endTime ?? ''),
            'participant_count' => (int)$xml->participantCount,
            'listener_count' => (int)$xml->listenerCount,
            'voice_participant_count' => (int)$xml->voiceParticipantCount,
            'video_count' => (int)$xml->videoCount,
            'max_users' => (int)$xml->maxUsers,
            'moderator_count' => (int)$xml->moderatorCount,
            'attendees' => $attendees,
            'metadata' => []
        ];
        
        // Metadata parse et
        if (isset($xml->metadata)) {
            foreach ($xml->metadata->children() as $key => $value) {
                $meetingInfo['metadata'][$key] = (string)$value;
            }
        }
        
        Log::info('BBB Meeting bilgileri alındı', [
            'meeting_id' => $meetingId,
            'is_running' => $meetingInfo['running'],
            'participant_count' => $meetingInfo['participant_count'],
            'attendees_count' => count($attendees)
        ]);
        
        return $meetingInfo;
    }

    /**
     * Toplantıyı sonlandırır
     * 
     * @param string $meetingId Toplantı ID'si
     * @param string $moderatorPw Moderator şifresi
     * @return array Sonuç
     */
    public function endMeeting(string $meetingId, string $moderatorPw)
    {
        Log::info('BBB endMeeting çağrıldı', ['meeting_id' => $meetingId]);
        
        $xml = $this->sendRequest('end', [
            'meetingID' => $meetingId,
            'password' => $moderatorPw
        ]);
        
        if (!$xml || (string)$xml->returncode !== 'SUCCESS') {
            Log::error('BBB Meeting sonlandırılamadı', [
                'meeting_id' => $meetingId,
                'returncode' => $xml ? (string)$xml->returncode : 'NO_RESPONSE',
                'message' => $xml ? (string)$xml->message : 'No response'
            ]);
            
            return [
                'success' => false,
                'error' => $xml ? (string)$xml->message : 'BBB sunucusuna bağlanılamadı'
            ];
        }
        
        Log::info('BBB Meeting başarıyla sonlandırıldı', [
            'meeting_id' => $meetingId,
            'message_key' => (string)$xml->messageKey,
            'message' => (string)$xml->message
        ]);
        
        return [
            'success' => true,
            'message_key' => (string)$xml->messageKey,
            'message' => (string)$xml->message
        ];
    }

    /**
     * Tüm toplantıları getirir
     * 
     * @return array Toplantı listesi
     */
    public function getMeetings()
    {
        Log::info('BBB getMeetings çağrıldı');
        
        $xml = $this->sendRequest('getMeetings');
        
        if (!$xml || (string)$xml->returncode !== 'SUCCESS') {
            Log::error('BBB Meetings listesi alınamadı', [
                'returncode' => $xml ? (string)$xml->returncode : 'NO_RESPONSE'
            ]);
            return [];
        }
        
        $meetings = [];
        if (isset($xml->meetings->meeting)) {
            foreach ($xml->meetings->meeting as $meeting) {
                $meetings[] = [
                    'meeting_name' => (string)$meeting->meetingName,
                    'meeting_id' => (string)$meeting->meetingID,
                    'internal_meeting_id' => (string)$meeting->internalMeetingID,
                    'create_time' => (string)$meeting->createTime,
                    'create_date' => (string)$meeting->createDate,
                    'voice_bridge' => (string)$meeting->voiceBridge,
                    'dial_number' => (string)$meeting->dialNumber,
                    'attendee_pw' => (string)$meeting->attendeePW,
                    'moderator_pw' => (string)$meeting->moderatorPW,
                    'running' => strtolower((string)$meeting->running) === 'true',
                    'duration' => (int)$meeting->duration,
                    'has_user_joined' => strtolower((string)$meeting->hasUserJoined) === 'true',
                    'recording' => strtolower((string)$meeting->recording) === 'true',
                    'has_been_forcibly_ended' => strtolower((string)$meeting->hasBeenForciblyEnded) === 'false',
                    'start_time' => (string)($meeting->startTime ?? ''),
                    'end_time' => (string)($meeting->endTime ?? ''),
                    'participant_count' => (int)$meeting->participantCount,
                    'listener_count' => (int)$meeting->listenerCount,
                    'voice_participant_count' => (int)$meeting->voiceParticipantCount,
                    'video_count' => (int)$meeting->videoCount,
                    'max_users' => (int)$meeting->maxUsers,
                    'moderator_count' => (int)$meeting->moderatorCount,
                ];
            }
        }
        
        Log::info('BBB Meetings listesi alındı', [
            'count' => count($meetings)
        ]);
        
        return $meetings;
    }
}
