/** UTF-8-preserving bounded JSON string encoding. */
void WhaleTracker_RustJsonEscape(const char[] input, char[] output, int maxlen)
{
    if (maxlen < 1) { return; }
    int write = 0;
    int inputLength = strlen(input);
    for (int read = 0; read < inputLength && write < maxlen - 1; read++)
    {
        int value = view_as<int>(input[read]) & 0xFF;
        if (value == '"' || value == '\\' || value == '\n' || value == '\r' || value == '\t')
        {
            if (write + 2 >= maxlen) { break; }
            output[write++] = '\\';
            if (value == '\n') { output[write++] = 'n'; }
            else if (value == '\r') { output[write++] = 'r'; }
            else if (value == '\t') { output[write++] = 't'; }
            else { output[write++] = input[read]; }
        }
        else if (value >= 128)
        {
            int count = value >= 0xC2 && value <= 0xDF ? 2 : (value >= 0xE0 && value <= 0xEF ? 3 : (value >= 0xF0 && value <= 0xF4 ? 4 : 0));
            bool valid = count > 0 && read + count <= inputLength;
            for (int j = 1; valid && j < count; j++)
            {
                int c = view_as<int>(input[read + j]) & 0xFF;
                valid = c >= 0x80 && c <= 0xBF;
            }
            if (valid)
            {
                int second = view_as<int>(input[read + 1]) & 0xFF;
                valid = !(value == 0xE0 && second < 0xA0) && !(value == 0xED && second > 0x9F)
                    && !(value == 0xF0 && second < 0x90) && !(value == 0xF4 && second > 0x8F);
            }
            if (!valid) { output[write++] = '?'; }
            else
            {
                if (write + count >= maxlen) { break; }
                for (int j = 0; j < count; j++) { output[write++] = input[read + j]; }
                read += count - 1;
            }
        }
        else if (value >= 32) { output[write++] = input[read]; }
    }
    output[write] = '\0';
}
