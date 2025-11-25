using System.Net.Http;
using System.Text.Json;
using System.Threading.Tasks;

public class TornApiService
{
    private static readonly HttpClient Http = new HttpClient();

    public async Task<bool> ValidateKeyAsync(string apiKey)
    {
        if (string.IsNullOrWhiteSpace(apiKey))
            return false;

        // Simple validation: call /user endpoint and check for "error"
        var url = $"https://api.torn.com/user/?selections=profile&key={apiKey.Trim()}";

        try
        {
            using var response = await Http.GetAsync(url);
            if (!response.IsSuccessStatusCode)
                return false;

            var json = await response.Content.ReadAsStringAsync();

            using var doc = JsonDocument.Parse(json);
            if (doc.RootElement.TryGetProperty("error", out _))
            {
                // Torn returns an "error" object when key is invalid, banned, etc.
                return false;
            }

            return true;
        }
        catch
        {
            // Network error, timeout, etc. – treat as invalid for now
            return false;
        }
    }
}
