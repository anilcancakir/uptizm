<?php

// Turkish translation of lang/en/validation.php; keep every ":placeholder" token
// and key path identical to the English source. Base translation reconciled
// from the community Laravel-Lang/lang tr locale against Laravel 13's
// published defaults; any key the community file lacked or left untranslated
// was translated here from scratch.
return [

    'accepted' => ':Attribute kabul edilmelidir.',
    'accepted_if' => ':Attribute, :other değeri :value ise kabul edilmelidir.',
    'active_url' => ':Attribute geçerli bir URL olmalıdır.',
    'after' => ':Attribute :date tarihinden sonra olmalıdır.',
    'after_or_equal' => ':Attribute :date tarihinden sonra veya aynı tarihte olmalıdır.',
    'alpha' => ':Attribute sadece harflerden oluşmalıdır.',
    'alpha_dash' => ':Attribute sadece harflerden, rakamlardan ve tirelerden oluşmalıdır.',
    'alpha_num' => ':Attribute sadece harflerden ve rakamlardan oluşmalıdır.',
    'any_of' => ':Attribute alanı geçersiz.',
    'array' => ':Attribute bir dizi olmalıdır.',
    'ascii' => ':Attribute yalnızca tek baytlık alfasayısal karakterler ve semboller içermelidir.',
    'before' => ':Attribute :date tarihinden önce olmalıdır.',
    'before_or_equal' => ':Attribute :date tarihinden önce veya aynı tarihte olmalıdır.',
    'between' => [
        'array' => ':Attribute :min - :max arasında öge içermelidir.',
        'file' => ':Attribute :min - :max kilobayt arasında olmalıdır.',
        'numeric' => ':Attribute :min - :max arasında olmalıdır.',
        'string' => ':Attribute :min - :max karakter arasında olmalıdır.',
    ],
    'boolean' => ':Attribute sadece doğru veya yanlış olmalıdır.',
    'can' => ':Attribute alanı yetkisiz bir değer içeriyor.',
    'confirmed' => ':Attribute onayı eşleşmiyor.',
    'contains' => ':Attribute alanında gerekli bir değer eksik.',
    'current_password' => 'Parola hatalı.',
    'date' => ':Attribute geçerli bir tarih değil.',
    'date_equals' => ':Attribute :date ile aynı tarihte olmalıdır.',
    'date_format' => ':Attribute :format biçiminde olmalıdır.',
    'decimal' => ':Attribute, :decimal ondalık basamaklara sahip olmalıdır.',
    'declined' => ':Attribute redd edilmelidir.',
    'declined_if' => ':Attribute, :other değeri :value iken redd edilmelidir.',
    'different' => ':Attribute ile :other birbirinden farklı olmalıdır.',
    'digits' => ':Attribute :digits basamaklı olmalıdır.',
    'digits_between' => ':Attribute en az :min, en fazla :max basamaklı olmalıdır.',
    'dimensions' => ':Attribute geçersiz resim boyutlarına sahip.',
    'distinct' => ':Attribute alanı yinelenen bir değere sahip.',
    'doesnt_contain' => ':Attribute alanı aşağıdakilerden hiçbirini içermemelidir: :values.',
    'doesnt_end_with' => ':Attribute aşağıdakilerden biriyle bitemez: :values.',
    'doesnt_start_with' => ':Attribute aşağıdakilerden biriyle başlamamalıdır: :values.',
    'email' => ':Attribute geçerli bir e-posta adresi olmalıdır.',
    'encoding' => ':Attribute alanı :encoding ile kodlanmış olmalıdır.',
    'ends_with' => ':Attribute sadece şu değerlerden biriyle bitebilir: :values.',
    'enum' => 'Seçilen :attribute değeri geçersiz.',
    'exists' => 'Seçili :attribute geçersiz.',
    'extensions' => ':Attribute alanı aşağıdaki uzantılardan birine sahip olmalıdır: :values.',
    'file' => ':Attribute bir dosya olmalıdır.',
    'filled' => ':Attribute doldurulmalıdır.',
    'gt' => [
        'array' => ':Attribute :value sayısından daha fazla öge içermelidir.',
        'file' => ':Attribute :value kilobayt\'tan büyük olmalıdır.',
        'numeric' => ':Attribute :value sayısından büyük olmalıdır.',
        'string' => ':Attribute :value karakterden uzun olmalıdır.',
    ],
    'gte' => [
        'array' => ':Attribute :value veya daha fazla öge içermelidir.',
        'file' => ':Attribute :value kilobayt\'tan büyük veya eşit olmalıdır.',
        'numeric' => ':Attribute :value sayısından büyük veya eşit olmalıdır.',
        'string' => ':Attribute :value karakterden uzun veya eşit olmalıdır.',
    ],
    'hex_color' => ':Attribute alanı geçerli bir onaltılık renk olmalıdır.',
    'image' => ':Attribute bir resim olmalıdır.',
    'in' => 'Seçili :attribute geçersiz.',
    'in_array' => ':Attribute :other içinde mevcut değil.',
    'in_array_keys' => ':Attribute alanı aşağıdaki anahtarlardan en az birini içermelidir: :values.',
    'integer' => ':Attribute bir tam sayı olmalıdır.',
    'ip' => ':Attribute geçerli bir IP adresi olmalıdır.',
    'ipv4' => ':Attribute geçerli bir IPv4 adresi olmalıdır.',
    'ipv6' => ':Attribute geçerli bir IPv6 adresi olmalıdır.',
    'json' => ':Attribute geçerli bir JSON içeriği olmalıdır.',
    'list' => ':Attribute alanı bir liste olmalıdır.',
    'lowercase' => ':Attribute küçük harf olmalıdır.',
    'lt' => [
        'array' => ':Attribute :value sayısından daha az öge içermelidir.',
        'file' => ':Attribute :value kilobayt\'tan küçük olmalıdır.',
        'numeric' => ':Attribute :value sayısından küçük olmalıdır.',
        'string' => ':Attribute :value karakterden kısa olmalıdır.',
    ],
    'lte' => [
        'array' => ':Attribute :value veya daha az öge içermelidir.',
        'file' => ':Attribute :value kilobayt\'tan küçük veya eşit olmalıdır.',
        'numeric' => ':Attribute :value sayısından küçük veya eşit olmalıdır.',
        'string' => ':Attribute :value karakterden kısa veya eşit olmalıdır.',
    ],
    'mac_address' => ':Attribute geçerli bir MAC adresi olmalıdır.',
    'max' => [
        'array' => ':Attribute en fazla :max öge içerebilir.',
        'file' => ':Attribute en fazla :max kilobayt olabilir.',
        'numeric' => ':Attribute en fazla :max olabilir.',
        'string' => ':Attribute en fazla :max karakter olabilir.',
    ],
    'max_digits' => ':Attribute en fazla :max basamak içermelidir.',
    'mimes' => ':Attribute :values biçiminde bir dosya olmalıdır.',
    'mimetypes' => ':Attribute :values biçiminde bir dosya olmalıdır.',
    'min' => [
        'array' => ':Attribute en az :min öge içermelidir.',
        'file' => ':Attribute en az :min kilobayt olmalıdır.',
        'numeric' => ':Attribute en az :min olmalıdır.',
        'string' => ':Attribute en az :min karakter olmalıdır.',
    ],
    'min_digits' => ':Attribute en az :min basamak içermelidir.',
    'missing' => ':Attribute alanı eksik olmalıdır.',
    'missing_if' => ':Other, :value olduğunda :attribute alanı eksik olmalıdır.',
    'missing_unless' => ':Other, :value değilse :attribute alanı eksik olmalıdır.',
    'missing_with' => ':Values mevcut olduğunda :attribute alanı eksik olmalıdır.',
    'missing_with_all' => ':Values mevcut olduğunda :attribute alanı eksik olmalıdır.',
    'multiple_of' => ':Attribute, :value değerinin katı olmalıdır.',
    'not_in' => 'Seçili :attribute geçersiz.',
    'not_regex' => ':Attribute biçimi geçersiz.',
    'numeric' => ':Attribute bir sayı olmalıdır.',
    'password' => [
        'letters' => ':Attribute en az bir harf içermelidir.',
        'mixed' => ':Attribute en az bir büyük harf ve bir küçük harf içermelidir.',
        'numbers' => ':Attribute en az bir sayı içermelidir.',
        'symbols' => ':Attribute en az bir sembol içermelidir.',
        'uncompromised' => 'Verilen :attribute bir veri sızıntısında ortaya çıktı. Lütfen farklı bir :attribute seçin.',
    ],
    'present' => ':Attribute mevcut olmalıdır.',
    'present_if' => ':Other, :value olduğunda :attribute alanı mevcut olmalıdır.',
    'present_unless' => ':Other, :value olmadığı sürece :attribute alanı mevcut olmalıdır.',
    'present_with' => ':Values mevcut olduğunda :attribute alanı mevcut olmalıdır.',
    'present_with_all' => ':Values mevcut olduğunda :attribute alanı mevcut olmalıdır.',
    'prohibited' => ':Attribute alanı kısıtlanmıştır.',
    'prohibited_if' => ':Other alanının değeri :value ise :attribute alanına veri girişi yapılamaz.',
    'prohibited_if_accepted' => ':Other kabul edildiğinde :attribute alanına veri girişi yapılamaz.',
    'prohibited_if_declined' => ':Other reddedildiğinde :attribute alanına veri girişi yapılamaz.',
    'prohibited_unless' => ':Other alanı :values değerlerinden birisi değilse :attribute alanına veri girişi yapılamaz.',
    'prohibits' => ':Attribute alanı :other alanının mevcut olmasını yasaklar.',
    'regex' => ':Attribute biçimi geçersiz.',
    'required' => ':Attribute alanı zorunludur.',
    'required_array_keys' => ':Attribute değeri şu verileri içermelidir: :values.',
    'required_if' => ':Attribute :other :value değerine sahip olduğunda gereklidir.',
    'required_if_accepted' => ':Attribute alanı, :other kabul edildiğinde gereklidir.',
    'required_if_declined' => ':Other seçeneği reddedildiğinde :attribute alanı gereklidir.',
    'required_unless' => ':Attribute :other :values değerlerinden birine sahip olmadığında gereklidir.',
    'required_with' => ':Attribute :values varken gereklidir.',
    'required_with_all' => ':Attribute herhangi bir :values değeri varken gereklidir.',
    'required_without' => ':Attribute :values yokken gereklidir.',
    'required_without_all' => ':Attribute :values değerlerinden herhangi biri yokken gereklidir.',
    'same' => ':Attribute ile :other eşleşmelidir.',
    'size' => [
        'array' => ':Attribute :size ögeye sahip olmalıdır.',
        'file' => ':Attribute :size kilobayt olmalıdır.',
        'numeric' => ':Attribute :size olmalıdır.',
        'string' => ':Attribute :size karakterli olmalıdır.',
    ],
    'starts_with' => ':Attribute sadece şu değerlerden biriyle başlayabilir: :values.',
    'string' => ':Attribute bir metin olmalıdır.',
    'timezone' => ':Attribute geçerli bir saat dilimi olmalıdır.',
    'unique' => ':Attribute zaten alınmış.',
    'uploaded' => ':Attribute yüklemesi başarısız.',
    'uppercase' => ':Attribute büyük harf olmalıdır.',
    'url' => ':Attribute geçerli bir URL olmalıdır.',
    'ulid' => ':Attribute geçerli bir ULID olmalıdır.',
    'uuid' => ':Attribute geçerli bir UUID olmalıdır.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [
        'credentials.token' => 'Bot jetonu',
        'credentials.channel' => 'Kanal',
        'credentials.url' => 'Uç nokta URL\'si',
        'credentials.secret' => 'İmzalama gizli anahtarı',
        'credentials.routing_key' => 'Yönlendirme anahtarı',
        'channel_type' => 'Kanal türü',
        'severity' => 'Önem derecesi',
        'is_enabled' => 'Etkin',
        'name' => 'Ad',

    ],

];
