<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class QualificationSeeder extends Seeder
{
    public function run(): void
    {
        $entries = [
            ['email' => 'alice@example.com',  'institute_name' => 'Parsons School of Design',   'degree' => 'Bachelor of Fine Arts',   'field_of_study' => 'Graphic Design',         'start_date' => '2014-09-01', 'end_date' => '2018-06-30'],
            ['email' => 'bob@example.com',    'institute_name' => 'MIT',                         'degree' => 'Bachelor of Science',     'field_of_study' => 'Computer Science',       'start_date' => '2013-09-01', 'end_date' => '2017-06-30'],
            ['email' => 'bob@example.com',    'institute_name' => 'Stanford University',         'degree' => 'Master of Science',       'field_of_study' => 'Software Engineering',   'start_date' => '2017-09-01', 'end_date' => '2019-06-30'],
            ['email' => 'carol@example.com',  'institute_name' => 'Ryerson University',          'degree' => 'Bachelor of Commerce',    'field_of_study' => 'Hospitality Management', 'start_date' => '2012-09-01', 'end_date' => '2016-06-30'],
            ['email' => 'david@example.com',  'institute_name' => 'University of Sydney',        'degree' => 'Bachelor of Business',    'field_of_study' => 'Marketing',              'start_date' => '2013-02-01', 'end_date' => '2016-11-30'],
            ['email' => 'eva@example.com',    'institute_name' => 'Berlin University of Arts',   'degree' => 'Bachelor of Arts',        'field_of_study' => 'Photography',            'start_date' => '2015-10-01', 'end_date' => '2019-07-31'],
            ['email' => 'frank@example.com',  'institute_name' => 'London Business School',      'degree' => 'MBA',                     'field_of_study' => 'Business Administration', 'start_date' => '2013-09-01', 'end_date' => '2015-06-30'],
            ['email' => 'grace@example.com',  'institute_name' => 'NASM',                        'degree' => 'Certification',           'field_of_study' => 'Personal Training',      'start_date' => '2017-01-01', 'end_date' => '2017-12-31'],
            ['email' => 'henry@example.com',  'institute_name' => 'Berklee College of Music',    'degree' => 'Bachelor of Music',       'field_of_study' => 'Music Production',       'start_date' => '2012-09-01', 'end_date' => '2016-05-31'],
            ['email' => 'isla@example.com',   'institute_name' => 'École Camondo Paris',         'degree' => 'Master of Arts',          'field_of_study' => 'Interior Design',        'start_date' => '2013-09-01', 'end_date' => '2017-06-30'],
            ['email' => 'jack@example.com',   'institute_name' => 'NUS Business School',         'degree' => 'Bachelor of Business',    'field_of_study' => 'E-Commerce',             'start_date' => '2014-08-01', 'end_date' => '2018-05-31'],
        ];

        foreach ($entries as $entry) {
            $user = User::where('email', $entry['email'])->first();
            if (! $user) {
                continue;
            }
            unset($entry['email']);
            $entry['user_id'] = $user->id;
            DB::table('qualifications')->insertOrIgnore($entry + ['created_at' => now(), 'updated_at' => now()]);
        }
    }
}
