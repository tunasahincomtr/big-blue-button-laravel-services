<?php

namespace App\Http\Controllers\Bekoda;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Dersler;
use App\Models\DerslerData;
use App\Models\User;
use App\Models\OgretmenProfile;
use App\Models\OgrenciProfile;
use Carbon\Carbon;

class BekodaController extends Controller
{
    public function panel()
    {
        // Kullanıcı girişini ve rolünü kontrol et, role göre yönlendir
        if (auth()->check()) {
            $user = auth()->user();
            if ($user->role === 'admin') {
                return redirect()->route('admin.dashboard');
            } elseif ($user->role === 'ogretmen') {
                return redirect()->route('ogretmen.dashboard');
            } elseif ($user->role === 'ogrenci') {
                return redirect()->route('ogrenci.dashboard');
            }
            // Eğer role bilinmiyorsa standart bir rota veya hata dönebilirsin
            abort(403, 'Yetki bulunamadı.');
        } else {
            // Eğer login değilse login sayfasına yönlendir
            return redirect()->route('login');
        }
    }
    /**
     * BBB Webhook handler
     * WebHookController'dan forward edilen webhook'ları işler
     */
    public function webhook(Request $request)
    {
        try {
        $data = $request->all();
            
            

            // Event string'i parse et
            $eventString = $data['event'] ?? null;
            $rawPayload = $data['raw_payload'] ?? null;
            
            // Eğer raw_payload varsa onu kullan, yoksa event string'i parse et
            $singleEvent = $rawPayload;
            if (!$singleEvent && $eventString) {
                $decoded = json_decode($eventString, true);
                $singleEvent = is_array($decoded) && isset($decoded[0]) ? $decoded[0] : $decoded;
            }

            if (!$singleEvent) {
                Log::warning('[Bekoda] Event parse edilemedi', ['data' => $data]);
                return response()->json(['success' => false, 'message' => 'Event parse edilemedi'], 400);
            }

            // External meeting ID'yi bul (ders ID'sini içerir) - BBB 2.x ve 3.x farklı path'ler
            $extId = data_get($singleEvent, 'core.body.props.meetingProp.extId')
                ?? data_get($singleEvent, 'data.attributes.meeting.external-meeting-id')
                ?? data_get($singleEvent, 'data.meeting.external-meeting-id')
                ?? data_get($singleEvent, 'envelope.routing.meetingId');

            // Internal meeting ID - BBB 2.x/3.x farklı formatlar
            $intId = data_get($singleEvent, 'core.body.props.meetingProp.intId')
                ?? data_get($singleEvent, 'data.attributes.meeting.meetingId')
                ?? data_get($singleEvent, 'data.attributes.meeting.internal-meeting-id')
                ?? data_get($singleEvent, 'data.meeting.meetingId')
                ?? data_get($singleEvent, 'data.meeting.internalMeetingID')
                ?? data_get($singleEvent, 'core.body.meetingId')
                ?? data_get($singleEvent, 'data.meetingId');

            // Event türü (BBB bazen data.id, bazen envelope.name kullanıyor; 3.x: meeting-created, user-joined, user-left, meeting-ended)
            $eventType = data_get($singleEvent, 'data.id')
                ?? data_get($singleEvent, 'envelope.name')
                ?? data_get($singleEvent, 'core.header.name');

            
            // extId'den ders ID'sini çıkar: baydur_lesson_{ders_id}_ogrt_{teacher_id}_ogr_{student_id}_{timestamp}
            $dersId = null;
            $ders = null;
            
            // Önce extId'den ders bulmayı dene
            if ($extId && preg_match('/baydur_lesson_(\d+)_ogrt_/', $extId, $matches)) {
                $dersId = (int)$matches[1];
                $ders = Dersler::find($dersId);
            }
            
            // Eğer extId'den ders bulunamadıysa, intId'den ders bulmayı dene
            if (!$ders && $intId) {
                // internal-meeting-id'den ders bul
                // MeetingCreated event'inde internal-meeting-id'yi de kaydediyoruz
                // Format: "extId|intId" veya sadece "intId" (eğer extId yoksa)
                // Önce bbb_meeting_id'de intId arayalım
                $ders = Dersler::where('bbb_meeting_id', 'LIKE', '%' . $intId . '%')
                    ->orWhere('bbb_meeting_id', $intId)
                    ->first();
            }

            if (!$ders) {
                Log::warning('[Bekoda] Ders bulunamadı', [
                    'ext_id' => $extId,
                    'int_id' => $intId,
                    'ders_id_from_ext' => $dersId,
                    'event_type' => $eventType,
                ]);
                return response()->json(['success' => false, 'message' => 'Ders bulunamadı'], 404);
            }
            
            // Ders bulundu, dersId'yi güncelle
            $dersId = $ders->id;

            // Event türüne göre işlem yap (BBB 2.x EvtMsg ve BBB 3.x tire-format alias'ları)
            $handled = false;
            switch ($eventType) {
                case 'MeetingCreatedEvtMsg':
                case 'meeting-created':
                    $this->handleMeetingCreated($ders, $extId, $intId, $singleEvent);
                    $handled = true;
                    break;

                case 'UserJoinedMeetingEvtMsg':
                case 'user-joined':
                    $this->handleUserJoined($ders, $singleEvent);
                    $handled = true;
                    break;

                case 'user-left':
                case 'UserLeftMeetingEvtMsg':
                    $this->handleUserLeft($ders, $singleEvent);
                    $handled = true;
                    break;

                case 'MeetingDestroyedEvtMsg':
                case 'meeting-ended':
                    $this->handleMeetingDestroyed($ders, $singleEvent);
                    $handled = true;
                    break;
            }

            if (!$handled) {
                Log::info('[Bekoda] İşlenmeyen event türü', [
                    'event_type' => $eventType,
                    'ders_id' => $dersId,
                ]);
            }

            return response()->json(['success' => true, 'message' => 'Webhook işlendi']);

        } catch (\Exception $e) {
            Log::error('[Bekoda] Webhook işleme hatası', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
            ]);
            return response()->json(['success' => false, 'message' => 'Webhook işlenirken hata oluştu'], 500);
        }
    }

    /**
     * Event payload'ından olay zamanını al (BBB timestamp varsa kullan, yoksa null).
     * Bazı BBB sürümleri ms cinsinden gönderir.
     */
    private function getEventTimestamp($event): ?Carbon
    {
        $ts = data_get($event, 'data.attributes.timestamp')
            ?? data_get($event, 'core.header.timestamp')
            ?? data_get($event, 'envelope.timestamp')
            ?? data_get($event, 'data.timestamp');
        if ($ts === null) {
            return null;
        }
        if (is_numeric($ts)) {
            $ts = (int) $ts;
            if ($ts > 1e12) {
                $ts = (int) ($ts / 1000);
            }
            return Carbon::createFromTimestamp($ts);
        }
        if (is_string($ts)) {
            try {
                return Carbon::parse($ts);
            } catch (\Exception $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * Meeting oluşturuldu event'i
     */
    private function handleMeetingCreated($ders, $extId, $intId, $event)
    {

        // Ders kaydını güncelle
        // bbb_meeting_id'ye hem external hem internal ID'yi kaydet
        // Format: "extId|intId" (eğer ikisi de varsa) veya sadece "intId" (eğer extId yoksa)
        $updateData = [];
        
        if (!$ders->bbb_meeting_id) {
            if ($extId && $intId) {
                // Hem external hem internal ID'yi kaydet
                $updateData['bbb_meeting_id'] = $extId . '|' . $intId;
            } elseif ($extId) {
                // Sadece external ID'yi kaydet
                $updateData['bbb_meeting_id'] = $extId;
            } elseif ($intId) {
                // Sadece internal ID'yi kaydet
                $updateData['bbb_meeting_id'] = $intId;
            }
        } elseif ($extId && $intId && !str_contains($ders->bbb_meeting_id, $intId)) {
            // Eğer bbb_meeting_id zaten var ama internal ID yoksa, ekle
            $updateData['bbb_meeting_id'] = $extId . '|' . $intId;
        }
        
        if (!empty($updateData)) {
            $ders->update($updateData);
            
        }
        
        // Öğretmen için DerslerData kaydı oluştur
        // Çünkü öğretmen ders başlattığında zaten meeting oluşturuluyor ve öğretmen join ediyor
        // UserJoined event'i gelmese bile, öğretmen için kayıt oluşturmalıyız
        $teacherData = DerslerData::firstOrNew([
            'dersler_id' => $ders->id,
            'user_id' => $ders->teacher_id,
        ]);
        
        if (!$teacherData->exists || !$teacherData->first_join_at) {
            $meetingCreatedAt = $this->getEventTimestamp($event) ?? Carbon::now();
            $teacherData->first_join_at = $meetingCreatedAt;
            $teacherData->role = 'ogretmen';
            $teacherData->join_count = ($teacherData->join_count ?? 0) + 1;
            
            $meta = $teacherData->meta ?? [];
            $meta['meeting_created_at'] = $meetingCreatedAt->toIso8601String();
            $meta['last_join_at'] = $meetingCreatedAt->toIso8601String();
            $meta['bbb_internal_meeting_id'] = $intId;
            $meta['bbb_external_meeting_id'] = $extId;
            $teacherData->meta = $meta;
            
            $teacherData->save();
            
        }
    }

    /**
     * Kullanıcı katıldı event'i
     */
    private function handleUserJoined($ders, $event)
    {
        // User bilgilerini al (BBB 2.x core.body / BBB 3.x data.attributes.user)
        $userIntId = data_get($event, 'core.body.intId')
            ?? data_get($event, 'data.attributes.user.internal-user-id')
            ?? data_get($event, 'data.attributes.user.user-id');
        
        $userExtId = data_get($event, 'core.body.extId')
            ?? data_get($event, 'data.attributes.user.external-user-id');
        
        $userName = data_get($event, 'core.body.name')
            ?? data_get($event, 'data.attributes.user.name');
        
        $userRole = data_get($event, 'core.body.role')
            ?? data_get($event, 'data.attributes.user.role')
            ?? data_get($event, 'data.user.role');



        // User role'üne göre user_id'yi bul (BBB bazen MODERATOR/VIEWER bazen moderator/viewer gönderir)
        $userRoleNorm = is_string($userRole) ? strtoupper($userRole) : '';
        $userId = null;
        if ($userRoleNorm === 'MODERATOR') {
            $userId = $ders->teacher_id;
        } elseif ($userRoleNorm === 'VIEWER') {
            $userId = $ders->student_id;
        }

        if (!$userId) {
            Log::warning('[Bekoda] User ID bulunamadı', [
                'ders_id' => $ders->id,
                'user_role' => $userRole,
                'teacher_id' => $ders->teacher_id,
                'student_id' => $ders->student_id,
            ]);
            return;
        }

        // DerslerData kaydını bul veya oluştur
        // Event timestamp kullan (webhook gecikmesi süreyi bozmasın)
        $now = $this->getEventTimestamp($event) ?? Carbon::now();
        $derslerData = DerslerData::firstOrNew([
            'dersler_id' => $ders->id,
            'user_id' => $userId,
        ]);

        // İlk katılım ise first_join_at set et
        if (!$derslerData->exists || !$derslerData->first_join_at) {
            $derslerData->first_join_at = $now;
        }

        // Join count'u artır
        $derslerData->join_count = ($derslerData->join_count ?? 0) + 1;
        $derslerData->role = $userRoleNorm === 'MODERATOR' ? 'ogretmen' : 'ogrenci';
        
        // Meta bilgileri - last_join_at her zaman güncellenmeli (event zamanı)
        $meta = $derslerData->meta ?? [];
        $meta['last_join_at'] = $now->toIso8601String();
        $meta['bbb_user_int_id'] = $userIntId;
        $meta['bbb_user_ext_id'] = $userExtId;
        $meta['bbb_user_name'] = $userName;
        $derslerData->meta = $meta;

        $derslerData->save();


    }

    /**
     * Kullanıcı ayrıldı event'i
     */
    private function handleUserLeft($ders, $event)
    {
        // User bilgilerini al (hem data.attributes hem de core.body formatlarını kontrol et)
        $userIntId = data_get($event, 'data.attributes.user.internal-user-id')
            ?? data_get($event, 'core.body.intId');
        
        $userExtId = data_get($event, 'data.attributes.user.external-user-id')
            ?? data_get($event, 'core.body.extId');
        
        $userName = data_get($event, 'data.attributes.user.name')
            ?? data_get($event, 'core.body.name');
        
        $userRole = data_get($event, 'data.attributes.user.role')
            ?? data_get($event, 'core.body.role');

        Log::info('[Bekoda] UserLeft işleniyor', [
            'ders_id' => $ders->id,
            'user_int_id' => $userIntId,
            'user_ext_id' => $userExtId,
            'user_name' => $userName,
            'user_role' => $userRole,
        ]);

        // User role'üne göre user_id'yi bul (BBB bazen MODERATOR/VIEWER bazen moderator/viewer)
        $userRoleNorm = is_string($userRole) ? strtoupper($userRole) : '';
        $userId = null;
        if ($userRoleNorm === 'MODERATOR') {
            $userId = $ders->teacher_id;
        } elseif ($userRoleNorm === 'VIEWER') {
            $userId = $ders->student_id;
        }

        if (!$userId) {
            Log::warning('[Bekoda] User ID bulunamadı (UserLeft)', [
                'ders_id' => $ders->id,
                'user_role' => $userRole,
            ]);
            return;
        }

        // DerslerData kaydını bul veya oluştur
        $derslerData = DerslerData::firstOrNew([
            'dersler_id' => $ders->id,
            'user_id' => $userId,
        ]);
        
        // Event timestamp kullan (webhook gecikmesi süreyi bozmasın)
        $now = $this->getEventTimestamp($event) ?? Carbon::now();
        if (!$derslerData->exists || !$derslerData->first_join_at) {
            // UserJoined event'i gelmemiş, bu durumda ders başlatıldığında oluşturulmuş olabilir
            // Eğer ders status 'basladi' ise ve ders başlatılma zamanını bulabilirsek onu kullan
            $dersBaslatZamani = $ders->updated_at; // Status 'basladi' olduğunda updated_at güncelleniyor
            
            if ($dersBaslatZamani && $ders->status === 'basladi') {
                $derslerData->first_join_at = Carbon::parse($dersBaslatZamani);
            } else {
                // Son çare: şimdiki zamandan 1 dakika öncesini kullan (minimum süre için)
                $derslerData->first_join_at = $now->copy()->subMinute(1);
            }
            
            $derslerData->role = $userRoleNorm === 'MODERATOR' ? 'ogretmen' : 'ogrenci';
            $derslerData->join_count = 1;
            
            $meta = $derslerData->meta ?? [];
            $meta['created_from_user_left'] = true;
            $meta['warning'] = 'UserJoined event\'i gelmemiş, first_join_at tahmini yapıldı';
            $meta['bbb_user_int_id'] = $userIntId;
            $meta['bbb_user_ext_id'] = $userExtId;
            $meta['bbb_user_name'] = $userName;
            $derslerData->meta = $meta;
            
            Log::warning('[Bekoda] UserLeft - DerslerData kaydı oluşturuldu (UserJoined gelmemiş)', [
                'ders_id' => $ders->id,
                'user_id' => $userId,
                'first_join_at' => $derslerData->first_join_at,
            ]);
        }
        
        $lastLeftAt = $now;
        
        // Meta'dan son join zamanını al (en önemli kısım - bu gerçek join zamanı)
        $meta = $derslerData->meta ?? [];
        $lastJoinAtStr = $meta['last_join_at'] ?? null;
        
        if ($lastJoinAtStr) {
            // last_join_at meta'dan geliyorsa, bu en doğru join zamanı
            $lastJoinAt = Carbon::parse($lastJoinAtStr);
        } elseif ($derslerData->first_join_at) {
            // last_join_at yoksa ama first_join_at varsa, onu kullan (tek seferlik katılım)
            $lastJoinAt = Carbon::parse($derslerData->first_join_at);
        } else {
            // Hiç join zamanı yoksa, şimdiki zamanı kullan (son çare)
            $lastJoinAt = $now;
            Log::warning('[Bekoda] UserLeft - Join zamanı bulunamadı, şimdiki zaman kullanılıyor', [
                'ders_id' => $ders->id,
                'user_id' => $userId,
            ]);
        }
        
        // Süre hesapla (saniye cinsinden) - lastJoinAt'den lastLeftAt'e kadar olan süre
        $durationSeconds = $lastLeftAt->diffInSeconds($lastJoinAt);
        
        // Negatif süre kontrolü (eğer lastJoinAt, lastLeftAt'den sonra ise)
        if ($durationSeconds < 0) {
            Log::warning('[Bekoda] UserLeft - Negatif süre tespit edildi, 0 yapılıyor', [
                'ders_id' => $ders->id,
                'user_id' => $userId,
                'last_join_at' => $lastJoinAt->toIso8601String(),
                'last_left_at' => $lastLeftAt->toIso8601String(),
                'duration_seconds' => $durationSeconds,
            ]);
            $durationSeconds = 0;
        }
        
        // Toplam süreyi güncelle
        $derslerData->total_duration_seconds = ($derslerData->total_duration_seconds ?? 0) + $durationSeconds;
        $derslerData->last_left_at = $lastLeftAt;
        
        // Meta bilgileri
        $meta['last_left_at'] = $lastLeftAt->toIso8601String();
        $meta['last_session_duration_seconds'] = $durationSeconds;
        $meta['last_session_start'] = $lastJoinAt->toIso8601String();
        $meta['last_session_end'] = $lastLeftAt->toIso8601String();
        $derslerData->meta = $meta;

        $derslerData->save();


        
        // Eğer hem öğretmen hem öğrenci ayrıldıysa, basit tamamlanma kuralına göre ders durumunu belirle
        $allParticipants = DerslerData::where('dersler_id', $ders->id)->get();
        $allLeft = true;
        foreach ($allParticipants as $participant) {
            if (!$participant->last_left_at) {
                $allLeft = false;
                break;
            }
        }

        if ($allLeft && $ders->status !== 'bitti') {
            // Toplantı bitişi = son ayrılanın last_left_at
            $meetingEndTime = Carbon::now();
            foreach ($allParticipants as $p) {
                if ($p->last_left_at) {
                    $t = Carbon::parse($p->last_left_at);
                    if ($t->gt($meetingEndTime)) {
                        $meetingEndTime = $t;
                    }
                }
            }

            $minSecondsForComplete = 30 * 60;
            $durationResult = $this->getMeetingDurationForCompletion($ders, $meetingEndTime);
            $completed = $durationResult['has_both'] && $durationResult['duration_seconds'] >= $minSecondsForComplete;



            if ($completed) {
                $prices = $this->calculateLessonPrices($ders);
                $ders->update([
                    'status' => 'bitti',
                    'teacher_price' => $prices['teacher_price'],
                    'student_price' => $prices['student_price'],
                ]);

            } else {
                $ders->update([
                    'teacher_price' => 0,
                    'student_price' => 0,
                ]);
            }
        }
    }

    /**
     * Meeting sonlandırıldı event'i
     */
    private function handleMeetingDestroyed($ders, $event)
    {

        // Önce tüm katılımcılar için son kalan süreleri hesapla ve güncelle
        $derslerDataList = DerslerData::where('dersler_id', $ders->id)
            ->get();

        // Event timestamp kullan (webhook gecikmesi süreyi bozmasın); yoksa şimdi
        $endTime = $this->getEventTimestamp($event) ?? Carbon::now();

        foreach ($derslerDataList as $derslerData) {
            // Eğer henüz ayrılmamışsa, son join zamanından itibaren süre hesapla
            if (!$derslerData->last_left_at) {
                $meta = $derslerData->meta ?? [];
                $lastJoinAtStr = $meta['last_join_at'] ?? null;
                
                // Önce meta'dan last_join_at'i al (en doğru zaman)
                if ($lastJoinAtStr) {
                    $lastJoinAt = Carbon::parse($lastJoinAtStr);
                } elseif ($derslerData->first_join_at) {
                    // last_join_at yoksa first_join_at'i kullan (tek seferlik katılım)
                    $lastJoinAt = Carbon::parse($derslerData->first_join_at);
                } else {
                    // Hiç join zamanı yoksa, bu kaydı atla (geçersiz kayıt)
                    Log::warning('[Bekoda] MeetingDestroyed - Join zamanı bulunamadı, kayıt atlanıyor', [
                            'ders_id' => $ders->id,
                            'user_id' => $derslerData->user_id,
                    ]);
                    continue;
                }
                
                // Süre hesapla (saniye cinsinden) - lastJoinAt'den toplantı bitişine kadar
                $durationSeconds = $endTime->diffInSeconds($lastJoinAt);
                
                // Negatif süre kontrolü
                if ($durationSeconds < 0) {
                    Log::warning('[Bekoda] MeetingDestroyed - Negatif süre tespit edildi, 0 yapılıyor', [
                        'ders_id' => $ders->id,
                        'user_id' => $derslerData->user_id,
                        'last_join_at' => $lastJoinAt->toIso8601String(),
                        'end_time' => $endTime->toIso8601String(),
                        'duration_seconds' => $durationSeconds,
                    ]);
                    $durationSeconds = 0;
                }
                
                $derslerData->total_duration_seconds = ($derslerData->total_duration_seconds ?? 0) + $durationSeconds;
                $derslerData->last_left_at = $endTime;
                
                $meta['last_left_at'] = $endTime->toIso8601String();
                $meta['final_session_duration_seconds'] = $durationSeconds;
                $meta['meeting_destroyed_at'] = $endTime->toIso8601String();
                $meta['final_session_start'] = $lastJoinAt->toIso8601String();
                $meta['final_session_end'] = $endTime->toIso8601String();
                $derslerData->meta = $meta;
                
                $derslerData->save();
                

            }
        }

        // Basit tamamlanma kuralı: Her iki taraf katıldı + toplantı süresi (son katılanın girişinden bitişe) >= 30 dk
        $minSecondsForComplete = 30 * 60;
        $durationResult = $this->getMeetingDurationForCompletion($ders, $endTime);
        $completed = $durationResult['has_both'] && $durationResult['duration_seconds'] >= $minSecondsForComplete;



        if ($completed) {
            $prices = $this->calculateLessonPrices($ders);
            $ders->update([
                'status' => 'bitti',
                'teacher_price' => $prices['teacher_price'],
                'student_price' => $prices['student_price'],
            ]);
            Log::info('[Bekoda] MeetingDestroyed - ders tamamlandı (her iki taraf katıldı, süre >= 30 dk)', [
                'ders_id' => $ders->id,
                'meeting_duration_seconds' => $durationResult['duration_seconds'],
            ]);
        } else {
            $ders->update([
                'teacher_price' => 0,
                'student_price' => 0,
            ]);
            Log::info('[Bekoda] MeetingDestroyed - ders tamamlanmadı', [
                'ders_id' => $ders->id,
                'has_both' => $durationResult['has_both'],
                'meeting_duration_seconds' => $durationResult['duration_seconds'],
            ]);
        }
    }

    /**
     * Tek katılımcının toplam süresi (saniye). Debug / log için.
     */
    private function getParticipantTotalSeconds(int $derslerId, int $userId): int
    {
        $data = DerslerData::where('dersler_id', $derslerId)->where('user_id', $userId)->first();
        return $data ? (int) ($data->total_duration_seconds ?? 0) : 0;
    }

    /**
     * Tamamlanma kuralı (basit): Her iki taraf katıldı + toplantı süresi >= 30 dk = tamamlandı.
     * Başlangıç = ikisinin de derste olduğu ilk an (son katılanın first_join_at).
     * Bitiş = verilen meetingEndTime (MeetingDestroyed zamanı veya son ayrılanın last_left_at).
     * Sekme kapatma (UserLeft gelmemesi) sorununu azaltır; sadece "ikisi de katıldı mı" ve "süre yeterli mi" bakılır.
     *
     * @return array{has_both: bool, duration_seconds: int}
     */
    private function getMeetingDurationForCompletion(Dersler $ders, Carbon $meetingEndTime): array
    {
        $teacherData = DerslerData::where('dersler_id', $ders->id)->where('user_id', $ders->teacher_id)->first();
        $studentData = DerslerData::where('dersler_id', $ders->id)->where('user_id', $ders->student_id)->first();

        if (!$teacherData || !$studentData) {
            return ['has_both' => false, 'duration_seconds' => 0];
        }

        $teacherStart = $teacherData->first_join_at ? Carbon::parse($teacherData->first_join_at) : null;
        $studentStart = $studentData->first_join_at ? Carbon::parse($studentData->first_join_at) : null;
        if (!$teacherStart || !$studentStart) {
            return ['has_both' => false, 'duration_seconds' => 0];
        }

        // İkisinin de derste olduğu ilk an = son katılanın girişi
        $meetingStart = $teacherStart->gt($studentStart) ? $teacherStart : $studentStart;
        $durationSeconds = (int) $meetingEndTime->diffInSeconds($meetingStart);
        if ($durationSeconds < 0) {
            $durationSeconds = 0;
        }

        return ['has_both' => true, 'duration_seconds' => $durationSeconds];
    }

    /**
     * Ders tamamlandı mı? (Her iki taraf katıldı + toplantı süresi >= 30 dk)
     */
    private function shouldMarkLessonCompleted(Dersler $ders, Carbon $meetingEndTime): bool
    {
        $minSecondsForComplete = 30 * 60;
        $result = $this->getMeetingDurationForCompletion($ders, $meetingEndTime);
        return $result['has_both'] && $result['duration_seconds'] >= $minSecondsForComplete;
    }

    /**
     * Ders fiyatlarını hesapla
     * Öğrenci sınıf seviyesine göre öğretmen fiyatını ve öğrenci ücretini belirle
     */
    private function calculateLessonPrices($ders)
    {
        $teacherPrice = 0;
        $studentPrice = 0;
        
        try {
            // Öğretmen profilini al
            $ogretmenProfile = OgretmenProfile::where('user_id', $ders->teacher_id)->first();
            
            // Öğrenci profilini al
            $ogrenciProfile = OgrenciProfile::where('user_id', $ders->student_id)->first();
            
            if ($ogretmenProfile && $ogrenciProfile) {
                // Öğrenci sınıf seviyesini al (örn: "5", "9", "1-A" gibi)
                $sinifSeviyesi = $ogrenciProfile->sinif_seviyesi;
                
                // Sınıf numarasını çıkar (sadece sayıyı al)
                preg_match('/(\d+)/', $sinifSeviyesi ?? '', $matches);
                $sinifNo = isset($matches[1]) ? (int)$matches[1] : 0;
                
                // Sınıf seviyesine göre öğretmen fiyatını belirle
                // İlkokul: 1-4, Ortaokul: 5-8, Lise: 9-12
                if ($sinifNo >= 1 && $sinifNo <= 4) {
                    // İlkokul
                    $teacherPrice = $ogretmenProfile->ilkokul_fiyati ?? 0;
                } elseif ($sinifNo >= 5 && $sinifNo <= 8) {
                    // Ortaokul
                    $teacherPrice = $ogretmenProfile->ortaokul_fiyati ?? 0;
                } elseif ($sinifNo >= 9 && $sinifNo <= 12) {
                    // Lise - liste_fiyati kullanılıyor (lise fiyatı olarak)
                    $teacherPrice = $ogretmenProfile->liste_fiyati ?? 0;
                } else {
                    // Belirsiz sınıf, liste fiyatı kullan
                    $teacherPrice = $ogretmenProfile->liste_fiyati ?? 0;
                }
                
                // Öğrenci ücretini öğrenci profilinden al
                $studentPrice = $ogrenciProfile->saatlik_ders_ucreti ?? 0;

            } else {
                Log::warning('[Bekoda] Profil bulunamadı, fiyat hesaplanamadı', [
                    'ders_id' => $ders->id,
                    'teacher_id' => $ders->teacher_id,
                    'student_id' => $ders->student_id,
                    'ogretmen_profile_exists' => $ogretmenProfile ? true : false,
                    'ogrenci_profile_exists' => $ogrenciProfile ? true : false,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('[Bekoda] Fiyat hesaplama hatası', [
                'ders_id' => $ders->id,
                'error' => $e->getMessage(),
            ]);
        }
        
        return [
            'teacher_price' => $teacherPrice,
            'student_price' => $studentPrice,
        ];
    }
}
