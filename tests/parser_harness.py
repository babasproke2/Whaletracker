#!/usr/bin/env python3
"""Execute a mechanical C++ translation of the shipped SourcePawn flat parser.
This checks parser logic and memory safety under ASan/UBSan, NOT SourcePawn
compilation or the SourceMod runtime. Requires a C++17 compiler.
"""
from __future__ import annotations
import json
import random
import re
import shutil
import subprocess
import tempfile
from pathlib import Path


def translate(source: str) -> str:
    source = source.replace('WTRustResponse', 'StatsResponse').replace('WTResponse_', 'Response_')
    text = source.replace('enum struct StatsResponse', 'struct StatsResponse')
    text = text.replace('\n}\n\nvoid Response_SkipSpace', '\n};\n\nvoid Response_SkipSpace', 1)
    text = text.replace('const char[] ', 'const char* ').replace('char[] ', 'char* ')
    text = text.replace('StatsResponse response)', 'StatsResponse &response)')
    text = re.sub(r'view_as<([^>]+)>', r'static_cast<\1>', text)
    text = text.replace('strncmp(text[at],', 'strncmp(text + at,')
    return '#include <cstring>\n#include <string>\n#include <iostream>\nbool StrEqual(const char* a,const char* b) {return std::strcmp(a,b)==0;}\n' + text + r'''
int main() {
    std::string input;
    while (std::getline(std::cin, input)) {
        StatsResponse result{};
        bool ok = Response_Parse(input.c_str(), result);
        std::cout << ok << " " << result.Kind << " " << result.BatchId << " "
            << result.Accepted << " " << result.Executed << " " << result.DbErrors << "\n";
    }
}
'''


def schema_check(text: str) -> dict | None:
    seen: set[str] = set()
    def pairs(items):
        result = {}
        for key, value in items:
            if key in {'type','batch_id','accepted','executed','db_errors'} and key in seen:
                raise ValueError('duplicate protocol key')
            seen.add(key)
            result[key] = value
        return result
    try:
        record = json.loads(text, object_pairs_hook=pairs, parse_constant=lambda x: (_ for _ in ()).throw(ValueError(x)))
        if not isinstance(record,dict) or record.get('type') not in ('hello_ack','ack','error','health'):
            return None
        for key in ('batch_id','accepted','executed','db_errors'):
            if key not in record or (key == 'batch_id' and record[key] is None):
                continue
            if type(record[key]) is not int or not 0 <= record[key] <= 2147483647:
                return None
        if any(isinstance(value,(dict,list)) for value in record.values()):
            return None
        return record
    except (ValueError,TypeError):
        return None


def run(parser: Path) -> dict:
    compiler = shutil.which('clang++') or shutil.which('g++')
    if compiler is None:
        return {'status':'skipped','reason':'No C++ compiler; this is not a SourcePawn compiler.'}
    cases = [
        '{"type":"hello_ack","proto":1,"ts":12345678901234567}',
        '{"type":"ack","batch_id":7,"accepted":0,"executed":0,"db_errors":0}',
        '{"type":"ack","batch_id":7,"accepted":1,"executed":1,"db_errors":0}',
        '{"type":"error","message":"embedded \\\"type\\\":\\\"ack\\\"","batch_id":null}',
        '{"type":"health","queue_depth":18446744073709551615,"value":1.234e-5}',
        '{"type":"ack","batch_id":1,"b\\u0061tch_id":2,"accepted":0,"executed":0,"db_errors":0}',
        '{"type":"ack","batch_id":2147483648}', '{"type":"ack","batch_id":-1}',
        '{"type":"ack","accepted":1.5}', '{"type":"ack","accepted":true}',
        '{"type":"ack","batch_id":1,"batch_id":1}', '{"type":"ack"} trailing',
        '{"type":"ack",}', '{"type":"ack","x":[1]}', '{"type":"ack","x":{"a":1}}',
        '{"type":"ack","x":"\\u00"}', '{"type":"ack","x":"\\u0000"}',
        '', '{', '{"type"', '{"type":', '{"type":"', '{"type":"ack"',
    ]
    rng = random.Random(20260911)
    for _ in range(3000):
        batch = rng.choice([0,1,2147483647,2147483648,-1,None,1.5,False])
        cases.append(json.dumps({'type':rng.choice(['ack','hello_ack','health','error']), 'batch_id':batch,
            'accepted':rng.choice([0,3,2147483647,-2]), 'executed':0,'db_errors':0,
            'note':rng.choice(['normal','unicode é界','escaped " quote','\\ slash','\t'])},ensure_ascii=True))
    seed = '{"type":"ack","batch_id":12,"accepted":3,"executed":3,"db_errors":0}'
    alphabet = '{}[]:,"\\0123456789truefalsenull e+-x'
    for _ in range(7000):
        value = seed
        for _ in range(rng.randrange(1,5)):
            at = rng.randrange(len(value)+1)
            value = value[:at] + rng.choice(alphabet) + value[min(len(value),at+rng.randrange(0,3)):]
        cases.append(value)
    with tempfile.TemporaryDirectory(prefix='sourcepawn-parser-proxy-') as directory:
        cpp=Path(directory)/'parser.cpp'; executable=Path(directory)/'parser'
        cpp.write_text(translate(parser.read_text()),encoding='utf-8')
        build = subprocess.run([compiler,'-std=c++17','-O1','-g','-fsanitize=address,undefined','-fno-omit-frame-pointer',str(cpp),'-o',str(executable)],capture_output=True,text=True,timeout=40)
        if build.returncode:
            raise RuntimeError(build.stderr)
        result = subprocess.run([str(executable)],input='\n'.join(cases)+'\n',capture_output=True,text=True,timeout=30)
        if result.returncode:
            raise AssertionError(result.stderr)
    outputs=result.stdout.splitlines()
    assert len(outputs)==len(cases),(len(outputs),len(cases))
    accepted=0
    for number,(case,output) in enumerate(zip(cases,outputs)):
        ok,kind,batch,admitted,executed,errors=map(int,output.split())
        expected=schema_check(case)
        assert bool(ok)==(expected is not None),(number,case,output,expected)
        if ok:
            accepted+=1
            assert kind=={'hello_ack':1,'ack':2,'error':3,'health':4}[expected['type']]
            assert batch==(expected.get('batch_id') or 0)
            assert admitted==expected.get('accepted',0)
            assert executed==expected.get('executed',0)
            assert errors==expected.get('db_errors',0)
    return {'status':'passed','cases':len(cases),'accepted_valid_cases':accepted,
        'sanitizers':['AddressSanitizer','UndefinedBehaviorSanitizer'],
        'scope':'mechanically translated parser logic; NOT SourcePawn compilation'}

if __name__=='__main__':
    import argparse
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('parser',type=Path)
    print(json.dumps(run(p.parse_args().parser),indent=2))
