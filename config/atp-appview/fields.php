<?php

/*
|--------------------------------------------------------------------------
| Fields
|--------------------------------------------------------------------------
|
| Record fields to filter, sort and count by, per collection. Each is a
| type, with [] for a list: string, uri, datetime, number or bool. A path
| reaches a field by another name or nested, and strings match without
| regard to case unless 'case' is 'exact'. appview:fields builds a field
| added here for records already indexed.
|
| The references a view's Count and ViewerRef parts read are fields too,
| and are kept without being listed here.
|
|     'com.example.post' => [
|         'tags' => 'string[]',
|         'createdAt' => 'datetime',
|         'parent' => ['type' => 'uri', 'path' => 'reply.parent.uri'],
|     ],
|
*/

return [];
